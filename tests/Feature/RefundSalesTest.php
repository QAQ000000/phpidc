<?php

declare(strict_types=1);

use App\Actions\CreateLocalCardProductAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ImportRedemptionCodesAction;
use App\Actions\RefundInvoiceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\RedemptionCode;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('completes a paid local card sale and a single audited wallet refund without reselling the key', function (bool $combo): void {
    Queue::fake();
    $product = app(CreateLocalCardProductAction::class)->execute('Refundable Sub2API card', '10.00', '0.00', 'CNY', 'Setup', DriverType::SUB2API);
    app(ImportRedemptionCodesAction::class)->execute($product, ['ONCE-DELIVERED-CARD'], 'Import');
    $buyer = User::factory()->create(['credit' => $combo ? '3.00' : '20.00']);
    $response = $this->actingAs($buyer)->postJson('/checkout', ['product_id' => $product->id, 'billing_cycle' => 'onetime', 'use_credit' => true, 'gateway' => 'epay_wechat'])->assertCreated();
    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    if ($combo) {
        app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'REFUND-SALE-TRADE', $invoice->invoice_no, '7.00'));
    }
    $task = FulfillmentTask::sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $this->get('/console/instances/'.$task->instance_id)->assertOk()->assertSee('ONCE-DELIVERED-CARD');
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $user, Invoice $subject): bool => $user->is($actor) && $subject->is($invoice));
    $refund = app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Approved nonrecoverable card refund', actor: $actor);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Repeat request', actor: $actor);
    expect($refund->amount)->toBe('10.00')->and($refund->type)->toBe(TransactionType::CREDIT_IN)
        ->and($buyer->fresh()->credit)->toBe($combo ? '10.00' : '20.00')->and($buyer->fresh()->frozen_credit)->toBe('0.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)->and($task->instance->status)->toBe(InstanceStatus::CANCELLED)
        ->and(RedemptionCode::sole()->status)->toBe('sold');
    $this->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('Refunded');
    $this->get('/console/instances/'.$task->instance_id)->assertOk()->assertSee('Cancelled')->assertHeader('Cache-Control', 'no-store, private');
    expect(FinancialTransaction::where('reference_key', 'refund:'.$invoice->id)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'refund_card_loss:'.$invoice->id)->count())->toBe(1);
    $this->actingAs(User::factory()->create())->get('/console/invoices/'.$invoice->id)->assertForbidden();
    $this->get('/console/instances/'.$task->instance_id)->assertForbidden();
})->with([false, true]);
