<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\InitiatePaymentAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('preserves failed payment attempts and generates a different order number on explicit retry', function (): void {
    app(ConfigureEpayAction::class)->execute('https://epay.test', 'merchant', 'test-key', 'Test setup');
    Http::fakeSequence()->push(['code' => 0])->push(['code' => 1, 'payurl' => 'https://epay.test/pay']);
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    $actor = User::findOrFail($invoice->user_id);
    $action = app(InitiatePaymentAction::class);
    $first = $action->execute($invoice, $actor);
    $second = $action->execute($invoice, $actor);
    expect($first->success)->toBeFalse()->and($second->success)->toBeTrue()->and($first->outTradeNo)->not->toBe($second->outTradeNo);
    $this->assertDatabaseHas('payment_attempts', ['out_trade_no' => $first->outTradeNo, 'status' => 'failed']);
    $this->assertDatabaseHas('payment_attempts', ['out_trade_no' => $second->outTradeNo, 'status' => 'created']);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects invalid invoice state before testing payment amounts', function (): void {
    Queue::fake();
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat', 'status' => InvoiceStatus::REFUNDED]);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'REFUNDED-TRADE', $invoice->invoice_no, '10.00'));
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED);
    $this->assertDatabaseCount('financial_transactions', 0);
    Queue::assertNothingPushed();
});

it('never guesses a callback order suffix when no exact attempt exists', function (): void {
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'UNKNOWN-SUFFIX', $invoice->invoice_no.'1', '10.00'));
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::UNKNOWN_INVOICE)
        ->and(PaymentCallback::sole()->invoice_id)->toBeNull()->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
});

it('rolls back callback deduplication and money effects when instance handoff fails', function (): void {
    Queue::fake();
    $invoice = Invoice::factory()->create(['gateway' => 'epay_wechat']);
    FulfillmentTask::creating(fn () => throw new RuntimeException('handoff failed'));
    try {
        expect(fn () => app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'ROLLBACK-TRADE', $invoice->invoice_no, '10.00')))
            ->toThrow(RuntimeException::class, 'handoff failed');
    } finally {
        FulfillmentTask::flushEventListeners();
    }
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    foreach (['payment_callbacks', 'financial_transactions', 'fulfillment_tasks', 'instances'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
});
