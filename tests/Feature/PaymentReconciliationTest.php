<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\BackfillPaymentAttentionCasesAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\ProcessWalletClearanceAction;
use App\Actions\ReconcilePaymentCallbackAction;
use App\Actions\RecordPaymentAttentionCaseAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\ReopenPaymentAttentionCaseAction;
use App\Actions\RequestClosedAccountWalletClearanceAction;
use App\Actions\RequestWalletClearanceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\NotificationManager;
use App\Services\PaymentAttentionCases;
use App\Services\SettingService;
use App\Support\Money;
use App\Support\WalletCapacity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function paymentReconciliationActor(): User
{
    $actor = User::factory()->create();
    foreach (['finance-reconciliation', 'wallet-clearance-reviewer', 'wallet-clearance-operator', 'wallet-clearance-confirmer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated reconciliation acceptance');
    }

    return $actor;
}

function paymentReconciliationInput(ReconciliationCase $case, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'expected_revision' => $case->revision,
        'reason' => 'Original receipt and current financial state verified', 'evidence' => 'PRIVATE-ORIGINAL-POSTING-EVIDENCE', 'confirm' => '1'], $changes);
}

function paymentReconciliationReceipt(Invoice $invoice, string $amount, ?string $outTradeNo = null): VerifiedPaymentResult
{
    $trade = 'RECONCILE-'.Str::ulid();

    return new VerifiedPaymentResult('epay_wechat', $trade, $outTradeNo ?? $invoice->invoice_no, $amount,
        ['out_trade_no' => $outTradeNo ?? $invoice->invoice_no, 'money' => $amount, 'trade_no' => $trade, 'original' => 'UNCHANGED-RAW-FACT']);
}

function paymentReconciliationFixture(string $amount = '11.00', string $walletPart = '4.00'): array
{
    $owner = User::factory()->create(['password' => 'Original-Password-123',
        'credit' => Money::sub(WalletCapacity::LIMIT, $walletPart), 'frozen_credit' => $walletPart]);
    $actor = paymentReconciliationActor();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat',
        'credit_applied' => $walletPart, 'gateway_amount' => Money::sub('10.00', $walletPart)]);
    if (! Money::isZero($walletPart)) {
        FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_FREEZE,
            'amount' => $walletPart, 'balance_after' => $owner->credit, 'frozen_after' => $walletPart, 'reference_key' => 'freeze:'.$invoice->id]);
    }
    $receipt = paymentReconciliationReceipt($invoice, $amount);
    app(HandlePaymentCallbackAction::class)->execute($receipt);

    return [$owner, $actor, $invoice, ReconciliationCase::sole(), PaymentCallback::sole(), $receipt];
}

function paymentReconciliationPay(WalletClearance $clearance, User $actor): WalletClearance
{
    $action = app(ProcessWalletClearanceAction::class);
    $input = fn (string $operation): array => ['request_id' => (string) Str::ulid(), 'operation' => $operation,
        'reason' => 'Isolated actual transfer confirmation', 'confirm' => '1'];
    $action->execute($clearance, $actor, $input('approve'));
    $started = $action->execute($clearance, $actor, $input('start'));

    return $action->execute($clearance, $actor, [...$input('paid'), 'intent_key' => $started->payout_intent_key,
        'external_reference' => 'RECONCILE-PAYOUT-'.$clearance->id, 'confirmed_amount' => $clearance->amount,
        'currency' => 'CNY', 'evidence' => 'PRIVATE-REAL-TRANSFER-EVIDENCE']);
}

function paymentReconciliationClear(User $owner, User $actor, string $amount, ?ReconciliationCase $source = null): WalletClearance
{
    $input = ['request_id' => (string) Str::ulid(), 'amount' => $amount, 'payout_method' => 'bank_transfer',
        'payee_name' => 'Original Financial Owner', 'payee_account' => 'PRIVATE-RECONCILE-ACCOUNT', 'bank_name' => 'Isolated Bank',
        'current_password' => 'Original-Password-123', 'confirm' => '1'];
    $clearance = $source === null ? app(RequestWalletClearanceAction::class)->execute($owner, $input)
        : app(RequestClosedAccountWalletClearanceAction::class)->execute($owner, $actor, [...$input,
            'reconciliation_case_id' => $source->id, 'reason' => 'Original rights verified', 'evidence' => 'PRIVATE-OLD-IDENTITY-PROOF']);

    return paymentReconciliationPay($clearance, $actor);
}

it('posts the original combo receipt only after real clearance and keeps callback and attention immutable', function (): void {
    [$owner, $actor, $invoice, $case, $callback, $receipt] = paymentReconciliationFixture();
    $original = $callback->getRawOriginal();
    $attention = DB::table('audit_logs')->where('action', 'payment.callback.attention')->get()->toJson();
    paymentReconciliationClear($owner, $actor, '1.00');
    $input = paymentReconciliationInput($case);
    $action = app(ReconcilePaymentCallbackAction::class);
    $updated = $action->execute($case, $actor, $input);
    expect($updated->status)->toBe('resolved')->and($updated->revision)->toBe(3)
        ->and(app(PaymentAttentionCases::class)->isResolved($updated))->toBeTrue()
        ->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT)->and($owner->fresh()->frozen_credit)->toBe('0.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->value('amount'))->toBe('4.00')
        ->and(FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->value('amount'))->toBe('6.00')
        ->and(FinancialTransaction::where('reference_key', 'overpaid:'.$callback->id)->value('amount'))->toBe('5.00')
        ->and(FulfillmentTask::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and(DB::table('notification_events')->where('event_type', 'invoice.paid')->count())->toBe(1)
        ->and(DB::table('reconciliation_entries')->where('action', 'payment_posted')->value('evidence'))->not->toContain('PRIVATE-ORIGINAL-POSTING-EVIDENCE');
    $counts = [FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()];
    $action->execute($case, $actor, $input);
    app(HandlePaymentCallbackAction::class)->execute($receipt);
    expect([FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()])->toBe($counts)
        ->and($callback->fresh()->getRawOriginal())->toBe($original)
        ->and(DB::table('audit_logs')->where('action', 'payment.callback.attention')->get()->toJson())->toBe($attention)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse();
    Http::assertNothingSent();
});

it('does not count reserved or unknown clearances as free capacity', function (): void {
    [$owner, $actor, , $case] = paymentReconciliationFixture();
    $clearance = app(RequestWalletClearanceAction::class)->execute($owner, ['request_id' => (string) Str::ulid(),
        'amount' => '1.00', 'payout_method' => 'bank_transfer', 'payee_name' => 'Owner', 'payee_account' => 'PRIVATE',
        'bank_name' => 'Bank', 'current_password' => 'Original-Password-123', 'confirm' => '1']);
    $operation = fn (string $value): array => ['request_id' => (string) Str::ulid(), 'operation' => $value, 'reason' => 'Actual check', 'confirm' => '1'];
    $process = app(ProcessWalletClearanceAction::class);
    $process->execute($clearance, $actor, $operation('approve'));
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    $started = $process->execute($clearance, $actor, $operation('start'));
    $process->execute($clearance, $actor, [...$operation('unknown'), 'intent_key' => $started->payout_intent_key, 'evidence' => 'Uncertain bank result']);
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    expect($owner->fresh()->wallet_clearance_frozen)->toBe('1.00')->and($owner->fresh()->frozen_credit)->toBe('4.00')
        ->and($case->fresh()->revision)->toBe(1)->and(FulfillmentTask::count())->toBe(0)
        ->and(FinancialTransaction::where('type', TransactionType::PAYMENT)->count())->toBe(0);
});

it('uses the current expired state and full receipt instead of settling an old wallet allocation', function (): void {
    [$owner, $actor, $invoice, $case, $callback] = paymentReconciliationFixture();
    $invoice->update(['expires_at' => now()->subMinute()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    paymentReconciliationClear($owner, $actor, '11.00');
    $updated = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    expect($updated->status)->toBe('resolved')->and(app(PaymentAttentionCases::class)->isResolved($updated))->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT)
        ->and(FinancialTransaction::where('reference_key', 'credit_in:callback:'.$callback->id)->value('amount'))->toBe('11.00')
        ->and(FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->count())->toBe(0)
        ->and(FulfillmentTask::count())->toBe(0);
});

it('treats the original receipt as a full second payment when another trade has since paid the invoice', function (): void {
    [$owner, $actor, $invoice, $case, $callback] = paymentReconciliationFixture();
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($invoice, '6.00'));
    paymentReconciliationClear($owner, $actor, '7.00');
    $updated = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    expect(app(PaymentAttentionCases::class)->isResolved($updated))->toBeTrue()
        ->and(FinancialTransaction::where('reference_key', 'credit_in:callback:'.$callback->id)->value('amount'))->toBe('11.00')
        ->and(FinancialTransaction::where('reference_key', 'overpaid:'.$callback->id)->count())->toBe(0)
        ->and(FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1)
        ->and(FulfillmentTask::where('invoice_id', $invoice->id)->count())->toBe(1)
        ->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT);
});

it('posts a blocked topup principal and excess once without creating service or affiliate work', function (): void {
    foreach (['wallet.topup_enabled' => '1', 'payment.epay_api_url' => 'https://epay.test', 'payment.epay_pid' => 'merchant', 'payment.epay_key' => 'secret'] as $key => $value) {
        [$group, $name] = explode('.', $key, 2);
        DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], ['value' => $name === 'epay_key' ? Crypt::encryptString($value) : $value,
            'is_encrypted' => $name === 'epay_key', 'created_at' => now(), 'updated_at' => now()]);
        app(SettingService::class)->forget($key);
    }
    $owner = User::factory()->create(['password' => 'Original-Password-123', 'credit' => '0.00']);
    $actor = paymentReconciliationActor();
    $invoice = app(CreateWalletTopupInvoiceAction::class)->execute($owner, ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'gateway' => 'epay_wechat']);
    $owner->forceFill(['credit' => WalletCapacity::LIMIT])->save();
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($invoice, '12.00'));
    $this->assertDatabaseHas('audit_logs', ['action' => 'payment.callback.attention', 'reason' => 'wallet_capacity']);
    $case = ReconciliationCase::sole();
    paymentReconciliationClear($owner, $actor, '12.00');
    $updated = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    expect(app(PaymentAttentionCases::class)->isResolved($updated))->toBeTrue()->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT)
        ->and(FinancialTransaction::where('reference_key', 'topup:'.$invoice->id)->value('amount'))->toBe('10.00')
        ->and(FinancialTransaction::where('reference_key', 'overpaid:'.$case->payment_callback_id)->value('amount'))->toBe('2.00')
        ->and(DB::table('notification_events')->where('event_type', 'wallet.topup.credited')->count())->toBe(1)
        ->and(Instance::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0)->and(DB::table('affiliate_commissions')->count())->toBe(0);
});

it('updates only the exact persisted merchant attempt and never guesses a suffix', function (): void {
    [$owner, $actor, $invoice, $case, $callback] = paymentReconciliationFixture();
    $original = $callback->getRawOriginal();
    $exact = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-EXACT', 'amount' => '6.00', 'status' => 'created']);
    $other = PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => $invoice->invoice_no.'-OTHER', 'amount' => '6.00', 'status' => 'created']);
    // A real second source captures the exact merchant number at the original callback boundary.
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($invoice, '11.00', $exact->out_trade_no));
    $exactCase = ReconciliationCase::where('id', '!=', $case->id)->sole();
    paymentReconciliationClear($owner, $actor, '1.00');
    app(ReconcilePaymentCallbackAction::class)->execute($exactCase, $actor, paymentReconciliationInput($exactCase));
    expect($exact->fresh()->status)->toBe('paid')->and($other->fresh()->status)->toBe('created')
        ->and($callback->fresh()->getRawOriginal())->toBe($original)->and($case->fresh()->status)->toBe('open');
});

it('keeps anonymous receipt obligations on the original identity through posting and partial external clearance', function (): void {
    $owner = User::factory()->create(['password' => 'Original-Password-123', 'credit' => '0.00']);
    $email = $owner->email;
    $fill = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $owner = app(CloseCustomerAccountAction::class)->execute($owner, 'Original-Password-123', true);
    $newOwner = User::factory()->create(['email' => $email]);
    $actor = paymentReconciliationActor();
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($fill, WalletCapacity::LIMIT));
    $fillCase = ReconciliationCase::sole();
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($invoice, '6.00'));
    $case = ReconciliationCase::where('id', '!=', $fillCase->id)->sole();
    $original = PaymentCallback::findOrFail($case->payment_callback_id)->getRawOriginal();
    paymentReconciliationClear($owner, $actor, '6.00', $fillCase);
    $posted = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    expect($posted->status)->toBe('awaiting_clearance')->and($posted->source_credit_transaction_id)->not->toBeNull()
        ->and(app(PaymentAttentionCases::class)->isResolved($posted))->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::EXPIRED)->and(FulfillmentTask::count())->toBe(0)
        ->and($newOwner->fresh()->credit)->toBe('0.00')
        ->and(DB::table('notification_events')->where('user_id', $newOwner->id)->count())->toBe(0);
    paymentReconciliationClear($owner, $actor, '2.00', $posted);
    expect($posted->fresh()->status)->toBe('awaiting_clearance');
    paymentReconciliationClear($owner, $actor, '4.00', $posted->fresh());
    expect(app(PaymentAttentionCases::class)->isResolved($posted->fresh()))->toBeTrue()
        ->and(PaymentCallback::findOrFail($case->payment_callback_id)->getRawOriginal())->toBe($original)
        ->and(User::withTrashed()->findOrFail($owner->id)->anonymized_at)->not->toBeNull()
        ->and($newOwner->fresh()->credit)->toBe('0.00');
});

it('credits an anomalous closed unpaid receipt without reviving the invoice or delivering', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $actor = paymentReconciliationActor();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(paymentReconciliationReceipt($invoice, '10.00'));
    $case = ReconciliationCase::sole();
    $posted = app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    expect($posted->status)->toBe('awaiting_clearance')->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)
        ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('10.00')
        ->and(FulfillmentTask::count())->toBe(0)->and(Instance::count())->toBe(0)
        ->and(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE);
});

it('requires a separate permission confirmation evidence stable key and current version', function (array $changes): void {
    [$owner, $actor, , $case] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case, $changes)))->toThrow(ValidationException::class);
    expect($case->fresh()->revision)->toBe(1)->and(FulfillmentTask::count())->toBe(0);
})->with(array_map(fn (array $row): array => [$row], [['confirm' => '0'], ['request_id' => 'not-a-key'], ['expected_revision' => 0],
    ['expected_revision' => 99], ['reason' => ' '], ['evidence' => '']]));

it('rejects a customer or ordinary financial operator without reconciliation permission', function (): void {
    [$owner, , , $case] = paymentReconciliationFixture();
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Ordinary financial role');
    foreach ([$owner, $actor] as $denied) {
        expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $denied, paymentReconciliationInput($case)))->toThrow(AuthorizationException::class);
    }
});

it('rejects changed replay intent and stale new requests without posting twice', function (): void {
    [$owner, $actor, , $case] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    $input = paymentReconciliationInput($case);
    $action = app(ReconcilePaymentCallbackAction::class);
    $action->execute($case, $actor, $input);
    $count = FinancialTransaction::count();
    expect(fn () => $action->execute($case, $actor, [...$input, 'evidence' => 'Changed proof']))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    expect(FinancialTransaction::count())->toBe($count);
});

it('does not admit short foreign currency or gateway mismatch attention through the capacity entry', function (string $mode): void {
    $owner = User::factory()->create();
    $actor = paymentReconciliationActor();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat', 'currency' => $mode === 'currency' ? 'USD' : 'CNY']);
    $receipt = new VerifiedPaymentResult($mode === 'gateway' ? 'epay_alipay' : 'epay_wechat', 'OTHER-'.Str::ulid(), $invoice->invoice_no, $mode === 'short' ? '1.00' : '10.00');
    app(HandlePaymentCallbackAction::class)->execute($receipt);
    $case = DB::transaction(fn () => app(RecordPaymentAttentionCaseAction::class)->execute(PaymentCallback::sole(), 'wallet_capacity'));
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    expect($owner->fresh()->credit)->toBe('0.00')->and(FinancialTransaction::count())->toBe(0)->and(FulfillmentTask::count())->toBe(0);
})->with(['short', 'currency', 'gateway']);

it('rejects damaged original source facts without touching any financial state', function (): void {
    [$owner, $actor, , $case, $callback] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    DB::table('payment_callbacks')->where('id', $callback->id)->update(['amount' => '12.00']);
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe('open')->and(FulfillmentTask::count())->toBe(0);
});

it('refuses damaged original combo reservations or partially posted unpaid invoices', function (string $damage): void {
    [$owner, $actor, $invoice, $case] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    if ($damage === 'freeze') {
        DB::table('financial_transactions')->where('reference_key', 'freeze:'.$invoice->id)->update(['amount' => '3.99']);
    } elseif ($damage === 'allocation') {
        $invoice->update(['gateway_amount' => '7.00']);
    } elseif ($damage === 'frozen_balance') {
        $owner->forceFill(['frozen_credit' => '3.00'])->save();
    } else {
        FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::PAYMENT,
            'amount' => '6.00', 'reference_key' => 'payment:'.$invoice->id]);
    }
    $before = FinancialTransaction::count();
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    expect(FinancialTransaction::count())->toBe($before)->and($case->fresh()->revision)->toBe(1)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and(FulfillmentTask::count())->toBe(0);
})->with(['freeze', 'allocation', 'frozen_balance', 'partial_payment']);

it('rolls back financial posting cases tasks and events when a durable write fails', function (string $failure): void {
    [$owner, $actor, $invoice, $case, $callback] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    $before = [$owner->fresh()->credit, $owner->fresh()->frozen_credit, FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()];
    $original = $callback->getRawOriginal();
    if ($failure === 'task') {
        FulfillmentTask::creating(fn () => throw new RuntimeException('Task write failed'));
    } elseif ($failure === 'entry') {
        ReconciliationEntry::creating(function (ReconciliationEntry $entry): void {
            if ($entry->action === 'payment_posted') {
                throw new RuntimeException('Entry write failed');
            }
        });
    } elseif ($failure === 'ledger') {
        FinancialTransaction::creating(function (FinancialTransaction $entry): void {
            if ($entry->type === TransactionType::PAYMENT) {
                throw new RuntimeException('Ledger write failed');
            }
        });
    } else {
        $this->mock(NotificationManager::class)->shouldReceive('queueOperations')->once()->andThrow(new RuntimeException('Outbox write failed'));
    }
    try {
        expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(RuntimeException::class);
    } finally {
        if ($failure === 'task') {
            FulfillmentTask::flushEventListeners();
        } elseif ($failure === 'entry') {
            ReconciliationEntry::flushEventListeners();
        } elseif ($failure === 'ledger') {
            FinancialTransaction::flushEventListeners();
        }
    }
    expect([$owner->fresh()->credit, $owner->fresh()->frozen_credit, FinancialTransaction::count(), ReconciliationEntry::count(), DB::table('notification_events')->count()])->toBe($before)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($case->fresh()->status)->toBe('open')
        ->and(FulfillmentTask::count())->toBe(0)->and(Instance::count())->toBe(0)->and($callback->fresh()->getRawOriginal())->toBe($original);
})->with(['task', 'entry', 'ledger', 'outbox']);

it('keeps original posting evidence valid after real delivery and an authorized later refund', function (): void {
    [$owner, $actor, $invoice, $case] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    RedemptionCode::factory()->create(['product_id' => $invoice->product_id]);
    $task = FulfillmentTask::where('invoice_id', $invoice->id)->sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeTrue();
    Gate::define('refund-invoice', fn (): bool => true);
    app(RefundInvoiceAction::class)->execute($invoice->fresh(), 'external_record', true, 'Real later external refund', $actor);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)
        ->and(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeTrue()
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse();
});

it('reopens only disproved evidence and reconfirms restored real results without posting again', function (): void {
    [$owner, $actor, $invoice, $case, $callback] = paymentReconciliationFixture();
    paymentReconciliationClear($owner, $actor, '1.00');
    app(ReconcilePaymentCallbackAction::class)->execute($case, $actor, paymentReconciliationInput($case));
    $case = $case->fresh();
    $reopen = app(ReopenPaymentAttentionCaseAction::class);
    expect(fn () => $reopen->execute($case, $actor, paymentReconciliationInput($case)))->toThrow(ValidationException::class);
    $paymentId = FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->value('id');
    DB::table('financial_transactions')->where('id', $paymentId)->update(['amount' => '5.99']);
    expect(app(PaymentAttentionCases::class)->isResolved($case))->toBeFalse()
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    $input = paymentReconciliationInput($case);
    $opened = $reopen->execute($case, $actor, $input);
    $reopen->execute($case, $actor, $input);
    expect($opened->status)->toBe('open')->and($opened->revision)->toBe(4)
        ->and(ReconciliationEntry::where('case_id', $case->id)->where('action', 'reopened')->count())->toBe(1);
    expect(fn () => app(ReconcilePaymentCallbackAction::class)->execute($opened, $actor, paymentReconciliationInput($opened)))->toThrow(ValidationException::class);
    DB::table('financial_transactions')->where('id', $paymentId)->update(['amount' => '6.00']);
    $count = FinancialTransaction::count();
    $resolved = app(ReconcilePaymentCallbackAction::class)->execute($opened, $actor, paymentReconciliationInput($opened));
    expect($resolved->status)->toBe('resolved')->and($resolved->revision)->toBe(6)
        ->and(app(PaymentAttentionCases::class)->isResolved($resolved))->toBeTrue()
        ->and(FinancialTransaction::count())->toBe($count)
        ->and(FinancialTransaction::where('reference_key', 'overpaid:'.$callback->id)->count())->toBe(1)
        ->and(FulfillmentTask::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('registers and clears only a closed original reservation release without fabricating a deposit', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now(), 'credit' => '4.00']);
    $actor = paymentReconciliationActor();
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'credit_applied' => '4.00', 'gateway_amount' => '6.00']);
    FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_FREEZE,
        'amount' => '4.00', 'reference_key' => 'freeze:'.$invoice->id]);
    $release = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_UNFREEZE,
        'amount' => '4.00', 'reference_key' => 'unfreeze:'.$invoice->id]);
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    $case = ReconciliationCase::sole();
    expect($case->source_type)->toBe('wallet_release')->and($case->source_credit_transaction_id)->toBe($release->id)
        ->and(app(PaymentAttentionCases::class)->assertSource($case)->id)->toBe($release->id)
        ->and(ReconciliationEntry::count())->toBe(1)->and(FinancialTransaction::where('type', TransactionType::CREDIT_IN)->count())->toBe(0);
    paymentReconciliationClear($owner, $actor, '4.00', $case);
    expect(app(PaymentAttentionCases::class)->isResolved($case->fresh()))->toBeTrue()
        ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('0.00')
        ->and(FinancialTransaction::where('reference_key', 'unfreeze:'.$invoice->id)->count())->toBe(1);
});

it('rejects release principal without original freeze evidence or with another settlement or refund claim', function (string $damage): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now(), 'credit' => '4.00']);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'credit_applied' => '4.00', 'gateway_amount' => '6.00']);
    FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_FREEZE,
        'amount' => $damage === 'freeze' ? '3.99' : '4.00', 'reference_key' => 'freeze:'.$invoice->id]);
    $release = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id,
        'type' => $damage === 'type' ? TransactionType::CREDIT_IN : TransactionType::CREDIT_UNFREEZE,
        'amount' => $damage === 'amount' ? '3.99' : '4.00', 'reference_key' => 'unfreeze:'.$invoice->id]);
    if (in_array($damage, ['settle', 'refund'], true)) {
        FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id,
            'type' => $damage === 'settle' ? TransactionType::CREDIT_SETTLE : TransactionType::CREDIT_IN,
            'amount' => $damage === 'settle' ? '4.00' : '10.00', 'reference_key' => $damage.':'.$invoice->id,
            'meta' => $damage === 'refund' ? ['mode' => 'refund_wallet', 'currency' => 'CNY'] : null]);
    }
    expect(fn () => DB::transaction(fn () => app(RecordPaymentAttentionCaseAction::class)->releasedCredit($owner, $release)))->toThrow(DomainException::class);
    if ($damage === 'refund') {
        $refund = FinancialTransaction::where('reference_key', 'refund:'.$invoice->id)->sole();
        expect(fn () => DB::transaction(fn () => app(RecordPaymentAttentionCaseAction::class)->refundCredit($owner, $refund)))->toThrow(DomainException::class);
    }
    expect(ReconciliationCase::count())->toBe(0)->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('4.00');
})->with(['type', 'amount', 'freeze', 'settle', 'refund']);
