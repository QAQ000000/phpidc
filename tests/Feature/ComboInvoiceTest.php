<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('freezes wallet credit on combo payment and unfreezes once when expired', function (): void {
    $user = User::factory()->create(['credit' => '5.00', 'frozen_credit' => '0.00']);
    $product = Product::factory()->create([
        'local_prices' => [
            'onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true],
        ],
    ]);

    $invoice = app(CreateComboInvoiceAction::class)->execute(
        user: $user,
        product: $product,
        billingCycle: BillingCycle::ONETIME,
        options: [],
        useCredit: true,
        gateway: 'epay_wechat', input: ['request_id' => (string) Str::ulid()],
    );

    expect($invoice->credit_applied)->toBe('5.00')
        ->and($invoice->gateway_amount)->toBe('5.00')
        ->and($user->fresh()->credit)->toBe('0.00')
        ->and($user->fresh()->frozen_credit)->toBe('5.00');

    $invoice->update(['expires_at' => now()->subMinute()]);

    app(ExpireUnpaidInvoicesAction::class)->execute();
    app(ExpireUnpaidInvoicesAction::class)->execute();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)
        ->and($user->fresh()->credit)->toBe('5.00')
        ->and($user->fresh()->frozen_credit)->toBe('0.00')
        // INV-W2: exactly one unfreeze ledger row despite two sweeps.
        ->and(FinancialTransaction::where('reference_key', "unfreeze:{$invoice->id}")->count())->toBe(1);
});

it('settles a full wallet purchase with an atomic instance and unique outbox task', function (): void {
    Queue::fake();
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '2.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    expect($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($invoice->total_amount)->toBe('12.00')
        ->and($invoice->gateway_amount)->toBe('0.00')
        ->and($invoice->instance_id)->not->toBeNull()
        ->and($user->fresh()->credit)->toBe('88.00')
        ->and($user->fresh()->frozen_credit)->toBe('0.00')
        ->and($invoice->line_items['subtotal'])->toBe('12.00');
    $this->assertDatabaseHas('fulfillment_tasks', ['invoice_id' => $invoice->id, 'instance_id' => $invoice->instance_id, 'idempotency_key' => "{$invoice->id}:provision:{$invoice->instance_id}"]);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => "settle:{$invoice->id}", 'amount' => '12.00']);
    $this->assertDatabaseCount('payment_attempts', 0);
});

it('rolls back wallet and invoice when outbox creation fails', function (): void {
    Queue::fake();
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    FulfillmentTask::creating(fn () => throw new RuntimeException('simulated outbox failure'));
    try {
        expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]))
            ->toThrow(RuntimeException::class, 'simulated outbox failure');
    } finally {
        FulfillmentTask::flushEventListeners();
    }
    expect($user->fresh()->credit)->toBe('100.00')->and($user->fresh()->frozen_credit)->toBe('0.00');
    foreach (['invoices', 'financial_transactions', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
});

it('rejects invalid provisioning rules before any wallet or invoice mutation in the purchase action', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['provisioning_rules' => ['hostname_mode' => 'custom'], 'local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]))
        ->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('invoices', 0);
});

it('does not release wallet funds from an invoice that is already paid', function (): void {
    Queue::fake();
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $invoice->update(['expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    expect($user->fresh()->credit)->toBe('90.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseMissing('financial_transactions', ['reference_key' => "unfreeze:{$invoice->id}"]);
});
