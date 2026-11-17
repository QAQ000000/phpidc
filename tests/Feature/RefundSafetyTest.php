<?php

declare(strict_types=1);

use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\RedemptionCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function refundFixture(array $attributes = [], ?InstanceStatus $state = InstanceStatus::CANCELLED): array
{
    $buyer = User::factory()->create(['credit' => '20.00', 'frozen_credit' => '7.00']);
    $instance = $state === null ? null : Instance::factory()->create(['user_id' => $buyer->id, 'status' => $state]);
    $invoice = Invoice::factory()->paid()->create($attributes + ['user_id' => $buyer->id, 'instance_id' => $instance?->id,
        'total_amount' => '100.00', 'credit_applied' => '30.00', 'gateway_amount' => '70.00', 'discount_amount' => '5.00', 'gateway' => 'epay_wechat']);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $user, Invoice $subject): bool => $user->is($actor) && $subject->is($invoice));

    return [$invoice, $actor, $buyer, $instance];
}

it('refunds the stored full total exactly once without touching unrelated frozen credit', function (string $mode): void {
    Http::preventStrayRequests();
    [$invoice, $actor, $buyer] = refundFixture();
    $action = app(RefundInvoiceAction::class);
    $first = $action->execute($invoice, $mode, reason: 'Approved refund', actor: $actor);
    $second = $action->execute($invoice, $mode, reason: 'Retry after lost response', actor: $actor);
    expect($first->id)->toBe($second->id)->and($first->amount)->toBe('100.00')
        ->and($first->type)->toBe($mode === 'refund_wallet' ? TransactionType::CREDIT_IN : TransactionType::EXPENSE)
        ->and($buyer->fresh()->credit)->toBe($mode === 'refund_wallet' ? '120.00' : '20.00')
        ->and($buyer->fresh()->frozen_credit)->toBe('7.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED);
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'invoice.refund', 'reason' => 'Approved refund', 'subject_id' => $invoice->id]);
    expect(DB::table('audit_logs')->where('action', 'invoice.refund')->count())->toBe(1);
    Http::assertNothingSent();
})->with(['refund_wallet', 'external_record']);

it('rejects changing the mode of an already completed refund', function (): void {
    [$invoice, $actor, $buyer] = refundFixture();
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Wallet refund', actor: $actor);
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'external_record', reason: 'Change to external', actor: $actor))->toThrow(DomainException::class);
    expect($buyer->fresh()->credit)->toBe('120.00');
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('requires explicit invoice authority and a supplied reason even for an invoice without an instance', function (string $invalid): void {
    [$invoice, $actor, $buyer] = refundFixture(state: null);
    $reason = 'Keep service authorized';
    $mode = 'refund_wallet';
    match ($invalid) {
        'no-actor' => $actor = null,
        'no-reason' => $reason = ' ',
        'long-reason' => $reason = str_repeat('a', 256),
        'wrong-actor' => $actor = User::factory()->create(),
        'wrong-invoice' => Gate::define('refund-invoice', fn (): bool => false),
        'wrong-mode' => $mode = 'automatic_gateway_refund',
    };
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, $mode, keepService: true, reason: $reason, actor: $actor))->toThrow(DomainException::class);
    expect($buyer->fresh()->credit)->toBe('20.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with(['no-actor', 'no-reason', 'long-reason', 'wrong-actor', 'wrong-invoice', 'wrong-mode']);

it('does not treat other nonpaid statuses as refundable', function (InvoiceStatus $state): void {
    [$invoice, $actor, $buyer] = refundFixture(['status' => $state]);
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Attempt refund', actor: $actor))->toThrow(DomainException::class);
    expect($buyer->fresh()->credit)->toBe('20.00')->and($invoice->fresh()->status)->toBe($state);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([InvoiceStatus::UNPAID, InvoiceStatus::CANCELLED, InvoiceStatus::EXPIRED, InvoiceStatus::REFUNDED]);

it('cancels pending commissions without deducting available affiliate balance or creating debt', function (): void {
    [$invoice, $actor] = refundFixture();
    $referrer = User::factory()->create(['credit' => '50.00', 'affiliate_balance' => '25.00']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id,
        'referrer_id' => $referrer->id, 'amount' => '10.00', 'status' => 'pending']);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', reason: 'Pending commission cancellation', actor: $actor);
    $clawback = FinancialTransaction::where('reference_key', 'clawback:'.$commission->id)->sole();
    expect($referrer->fresh()->affiliate_balance)->toBe('25.00')->and($referrer->fresh()->credit)->toBe('50.00')
        ->and($commission->fresh()->status)->toBe('clawed_back')->and($commission->fresh()->clawed_back_at)->not->toBeNull()
        ->and($clawback->amount)->toBe('10.00')->and($clawback->meta['previous_status'])->toBe('pending')
        ->and($clawback->meta['recovered_amount'])->toBe('0.00')->and($clawback->meta['receivable_amount'])->toBe('0.00');
});

it('recovers available commissions and records every unrecovered unit as a receivable', function (string $balance, string $after, string $recovered, string $debt): void {
    Log::spy();
    [$invoice, $actor] = refundFixture();
    $referrer = User::factory()->create(['credit' => '80.00', 'frozen_credit' => '9.00', 'affiliate_balance' => $balance]);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id,
        'referrer_id' => $referrer->id, 'amount' => '10.00', 'status' => 'available', 'available_at' => now()->subDay()]);
    $action = app(RefundInvoiceAction::class);
    $action->execute($invoice, 'refund_wallet', reason: 'Available commission refund', actor: $actor);
    $action->execute($invoice, 'refund_wallet', reason: 'Repeat refund', actor: $actor);
    $row = FinancialTransaction::where('reference_key', 'clawback:'.$commission->id)->sole();
    expect($referrer->fresh()->affiliate_balance)->toBe($after)->and($referrer->fresh()->credit)->toBe('80.00')
        ->and($referrer->fresh()->frozen_credit)->toBe('9.00')->and($commission->fresh()->status)->toBe('clawed_back')
        ->and($row->type)->toBe(TransactionType::AFFILIATE_CLAWBACK)->and($row->amount)->toBe('10.00')
        ->and($row->meta['affiliate_balance_before'])->toBe($balance)->and($row->meta['affiliate_balance_after'])->toBe($after)
        ->and($row->meta['recovered_amount'])->toBe($recovered)->and($row->meta['receivable_amount'])->toBe($debt);
    expect(FinancialTransaction::where('type', TransactionType::AFFILIATE_CLAWBACK)->count())->toBe(1);
    if ($debt !== '0.00') {
        Log::shouldHaveReceived('warning')->once()->with('Affiliate clawback receivable requires collection.', Mockery::on(fn (array $context): bool => $context['receivable_amount'] === $debt && $context['commission_id'] === $commission->id));
    } else {
        Log::shouldNotHaveReceived('warning');
    }
})->with([['25.00', '15.00', '10.00', '0.00'], ['3.25', '0.00', '3.25', '6.75'], ['0.00', '0.00', '0.00', '10.00']]);

it('does not claw back an already reversed commission a second time', function (): void {
    [$invoice, $actor] = refundFixture();
    $referrer = User::factory()->create(['affiliate_balance' => '5.00']);
    AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id, 'referrer_id' => $referrer->id,
        'status' => 'clawed_back', 'clawed_back_at' => now()->subDay()]);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', reason: 'Refund after previous reversal', actor: $actor);
    expect($referrer->fresh()->affiliate_balance)->toBe('5.00');
    expect(FinancialTransaction::where('type', TransactionType::AFFILIATE_CLAWBACK)->count())->toBe(0);
});

it('keeps delivered cards sold and records an unrecoverable refund without leaking keys', function (string $mode): void {
    [$invoice, $actor, $buyer, $instance] = refundFixture(state: InstanceStatus::ACTIVE);
    $instance->update(['encrypted_credentials' => ['card_key' => 'REFUNDED-SECRET-CARD']]);
    $code = RedemptionCode::factory()->create(['product_id' => $instance->product_id, 'instance_id' => $instance->id,
        'code' => 'REFUNDED-SECRET-CARD', 'status' => 'sold', 'sold_at' => now()]);
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::RUNNING,
        'locked_by' => 'old-worker', 'locked_at' => now(), 'lease_expires_at' => now()->addMinute()]);
    $action = app(RefundInvoiceAction::class);
    $action->execute($invoice, $mode, keepService: true, reason: 'Accept compromised card loss', actor: $actor);
    $action->execute($invoice, $mode, keepService: true, reason: 'Repeat request', actor: $actor);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($code->fresh()->status)->toBe('sold')->and($code->fresh()->instance_id)->toBe($instance->id)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::CANCELLED)->and($instance->fresh()->encrypted_credentials['card_key'])->toBe('REFUNDED-SECRET-CARD')
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)->and($task->fresh()->locked_by)->toBeNull()
        ->and($buyer->fresh()->credit)->toBe($mode === 'refund_wallet' ? '120.00' : '20.00');
    $expense = FinancialTransaction::where('invoice_id', $invoice->id)->where('type', TransactionType::EXPENSE)->sole();
    expect($expense->amount)->toBe('100.00');
    expect(DB::table('audit_logs')->pluck('after')->implode(' '))->not->toContain('REFUNDED-SECRET-CARD');
    expect(DB::table('financial_transactions')->pluck('meta')->implode(' '))->not->toContain('REFUNDED-SECRET-CARD');
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.refund_cancel', 'actor_id' => $actor->id, 'subject_id' => $instance->id]);
})->with(['refund_wallet', 'external_record']);

it('keeps an explicitly waived upstream service and performs no upstream termination', function (): void {
    Http::preventStrayRequests();
    [$invoice, $actor, , $instance] = refundFixture(state: InstanceStatus::ACTIVE);
    $instance->update(['driver_type' => DriverType::ZJMF_API]);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', keepService: true, reason: 'Approved goodwill refund retaining upstream host', actor: $actor);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
    Http::assertNothingSent();
});

it('rolls back wallet, clawback, receivable, card cancellation and stale tasks on a failed refund write', function (): void {
    Log::spy();
    [$invoice, $actor, $buyer, $instance] = refundFixture(state: InstanceStatus::ACTIVE);
    $referrer = User::factory()->create(['affiliate_balance' => '3.00']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $buyer->id,
        'referrer_id' => $referrer->id, 'amount' => '10.00', 'status' => 'available']);
    $code = RedemptionCode::factory()->create(['instance_id' => $instance->id, 'product_id' => $instance->product_id, 'status' => 'sold', 'sold_at' => now()]);
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id]);
    Invoice::updating(function (Invoice $model): void {
        if ($model->status === InvoiceStatus::REFUNDED) {
            throw new RuntimeException('Simulated refund write failure');
        }
    });
    try {
        expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Rollback test', actor: $actor))
            ->toThrow(RuntimeException::class, 'Simulated refund write failure');
    } finally {
        Invoice::flushEventListeners();
    }
    expect($buyer->fresh()->credit)->toBe('20.00')->and($buyer->fresh()->frozen_credit)->toBe('7.00')
        ->and($referrer->fresh()->affiliate_balance)->toBe('3.00')->and($commission->fresh()->status)->toBe('available')
        ->and($commission->fresh()->clawed_back_at)->toBeNull()->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($code->fresh()->status)->toBe('sold')
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
    Log::shouldNotHaveReceived('warning');
});

it('does not reprovision a refunded order on a new callback or a manual task retry', function (): void {
    [$invoice, $actor, $buyer, $instance] = refundFixture();
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id]);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Order cancelled', actor: $actor);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'NEW-TRADE-AFTER-REFUND', $invoice->invoice_no, '70.00'));
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Try again'))->toThrow(DomainException::class);
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE)
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)->and($buyer->fresh()->credit)->toBe('120.00');
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseCount('instances', 1);
});

it('does not silently overflow the wallet on refund', function (): void {
    [$invoice, $actor, $buyer] = refundFixture();
    $buyer->forceFill(['credit' => '9999999999.00'])->save();
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Large wallet', actor: $actor))->toThrow(DomainException::class);
    expect($buyer->fresh()->credit)->toBe('9999999999.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects restarting an old failed task after a refund even when the service was explicitly retained', function (): void {
    [$invoice, $actor, , $instance] = refundFixture(state: InstanceStatus::PENDING);
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Waived termination', actor: $actor);
    expect(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, 'Retry old failed order'))->toThrow(DomainException::class, 'A refunded invoice cannot restart fulfillment.');
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($instance->fresh()->status)->toBe(InstanceStatus::PENDING);
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('rolls back the entire refund when its mandatory audit row cannot be stored', function (): void {
    [$invoice, $actor, $buyer] = refundFixture();
    DB::unprepared("CREATE TRIGGER reject_refund_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'invoice.refund' BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Audit failure', actor: $actor))
        ->toThrow(QueryException::class, 'Audit unavailable');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($buyer->fresh()->credit)->toBe('20.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
});
