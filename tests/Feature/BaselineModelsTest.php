<?php

declare(strict_types=1);

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\Coupon;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\Product;

it('creates a fulfillment fixture with one owner and product across its linked records', function (): void {
    $task = FulfillmentTask::factory()->create()->fresh();
    $instance = $task->instance;
    $invoice = $task->invoice;

    expect($task->status)->toBe(FulfillmentTaskStatus::PENDING)
        ->and($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($task->user_id)->toBe($instance->user_id)
        ->and($invoice->user_id)->toBe($instance->user_id)
        ->and($invoice->instance_id)->toBe($instance->id)
        ->and($invoice->product_id)->toBe($instance->product_id)
        ->and($task->driver_type)->toBe($instance->driver_type)
        ->and($task->idempotency_key)->toBe("{$invoice->id}:provision:{$instance->id}")
        ->and($instance->product->category->products->modelKeys())->toContain($instance->product_id);
})->group('phase0');

it('creates a callback fixture linked to its gateway invoice without settling it', function (): void {
    $callback = PaymentCallback::factory()->create()->fresh();

    expect($callback->invoice_no)->toBe($callback->invoice->invoice_no)
        ->and($callback->gateway)->toBe($callback->invoice->gateway)
        ->and($callback->outcome)->toBe(PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT)
        ->and($callback->invoice->status)->toBe(InvoiceStatus::UNPAID);
})->group('phase0');

it('persists product configuration and coupon data through the existing model relationships', function (): void {
    $product = Product::factory()->create();
    $bounds = ['allow_downgrade' => true, 'min_value' => 1, 'allowed_item_ids' => []];
    $group = $product->optionGroups()->create([
        'name' => 'Bandwidth',
        'type' => 'quantity',
        'downgrade_bounds' => $bounds,
    ]);
    $field = $product->customFields()->create([
        'name' => 'Operating system',
        'field_type' => 'select',
        'options' => ['Debian', 'Ubuntu'],
        'is_required' => true,
    ]);
    $coupon = Coupon::create([
        'code' => 'BASELINE',
        'type' => 'fixed',
        'value' => '1.25',
        'product_ids' => [$product->id],
        'claim_conditions' => ['require_kyc' => true],
        'valid_until' => now()->addDay(),
    ]);
    $invoice = Invoice::factory()->create(['product_id' => $product->id, 'coupon_id' => $coupon->id]);

    expect($product->fresh()->optionGroups->first()->downgrade_bounds)->toBe($bounds)
        ->and($group->product->is($product))->toBeTrue()
        ->and($product->fresh()->customFields->first()->options)->toBe(['Debian', 'Ubuntu'])
        ->and($field->fresh()->is_required)->toBeTrue()
        ->and($field->product->is($product))->toBeTrue()
        ->and($invoice->coupon->product_ids)->toBe([$product->id])
        ->and($invoice->coupon->claim_conditions)->toBe(['require_kyc' => true])
        ->and($invoice->coupon->value)->toBe('1.25')
        ->and($invoice->coupon->valid_until->isFuture())->toBeTrue();
})->group('phase0');
