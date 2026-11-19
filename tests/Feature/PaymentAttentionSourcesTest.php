<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\BackfillPaymentAttentionCasesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\RecordPaymentAttentionCaseAction;
use App\Actions\RefundInvoiceAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\PaymentAttentionCases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

it('retains a legal closed customer wallet refund as one verifiable clearance source', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated refund source verification');
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'total_amount' => '12.34', 'gateway_amount' => '12.34']);
    $credit = app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Verified original refund', $actor);
    $case = ReconciliationCase::sole();
    expect($case->source_type)->toBe('wallet_credit')->and($case->payment_callback_id)->toBeNull()
        ->and($case->source_credit_transaction_id)->toBe($credit->id)->and($case->user_id)->toBe($owner->id)
        ->and($case->status)->toBe('awaiting_clearance')->and(app(PaymentAttentionCases::class)->assertSource($case)->id)->toBe($credit->id)
        ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('12.34');
    expect(app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Verified original refund', $actor)->id)->toBe($credit->id);
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    expect(ReconciliationCase::count())->toBe(1)->and(ReconciliationEntry::count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'refund:'.$invoice->id)->count())->toBe(1);
});

it('does not add a financial exception case to an ordinary active wallet refund', function (): void {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated active refund');
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id]);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Verified active refund', $actor);
    expect(ReconciliationCase::count())->toBe(0)->and($owner->fresh()->credit)->toBe('10.00');
});

it('preserves zero amount refunds for a closed original identity without inventing a clearance obligation', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated zero refund');
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'total_amount' => '0.00', 'gateway_amount' => '0.00']);
    $credit = app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Verified zero refund', $actor);
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    expect($credit->amount)->toBe('0.00')->and(ReconciliationCase::count())->toBe(0)
        ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('0.00');
});

it('keeps credit fingerprints stable when a JSON database reorders object keys', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id]);
    $credit = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_IN,
        'amount' => '10.00', 'reference_key' => 'refund:'.$invoice->id, 'meta' => ['mode' => 'refund_wallet', 'currency' => 'CNY', 'actor_id' => $owner->id]]);
    $expected = app(PaymentAttentionCases::class)->creditFingerprint($credit);
    DB::table('financial_transactions')->where('id', $credit->id)->update(['meta' => json_encode(['actor_id' => $owner->id, 'currency' => 'CNY', 'mode' => 'refund_wallet'])]);
    expect(app(PaymentAttentionCases::class)->creditFingerprint($credit->fresh()))->toBe($expected);
});

it('records a closed identity callback source atomically without letting a reused email claim it', function (): void {
    $owner = User::factory()->create(['email' => 'closed-original@example.invalid', 'deleted_at' => now(), 'anonymized_at' => now()]);
    $newOwner = User::factory()->create(['email' => 'new-reused@example.invalid']);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $input = new VerifiedPaymentResult('epay_wechat', 'CLOSED-SOURCE-ONE', $invoice->invoice_no, '5.00', [], 'success');
    expect(app(HandlePaymentCallbackAction::class)->execute($input))->toBe('success');
    $case = ReconciliationCase::sole();
    expect($case->source_type)->toBe('payment_callback')->and($case->status)->toBe('awaiting_clearance')
        ->and(app(PaymentAttentionCases::class)->assertSource($case)->amount)->toBe('5.00')
        ->and($newOwner->fresh()->credit)->toBe('0.00');
    app(HandlePaymentCallbackAction::class)->execute($input);
    expect(ReconciliationCase::count())->toBe(1)->and(ReconciliationEntry::count())->toBe(1)
        ->and(FinancialTransaction::where('reference_key', 'credit_in:callback:'.$case->payment_callback_id)->count())->toBe(1);
    $case->update(['status' => 'resolved', 'resolved_at' => now()]);
    expect(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
});

it('acknowledges a zero late receipt for a closed identity and verifies its zero obligation without a payout', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $input = new VerifiedPaymentResult('epay_wechat', 'ZERO-CLOSED-SOURCE', $invoice->invoice_no, '0.00');
    expect(app(HandlePaymentCallbackAction::class)->execute($input))->toBe('success');
    $case = ReconciliationCase::sole();
    expect($case->status)->toBe('resolved')->and($case->revision)->toBe(2)
        ->and(app(PaymentAttentionCases::class)->isResolved($case))->toBeTrue()
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse()
        ->and(DB::table('wallet_clearances')->count())->toBe(0)->and(DB::table('external_payout_receipts')->count())->toBe(0)
        ->and(PaymentCallback::count())->toBe(1)->and(ReconciliationEntry::count())->toBe(2);
});

it('keeps registered historical callback attention with missing source proof blocking', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $callback = PaymentCallback::factory()->create(['invoice_id' => $invoice->id]);
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'payment.callback.attention', 'subject_type' => PaymentCallback::class,
        'subject_id' => $callback->id, 'reason' => 'wallet_capacity', 'after' => json_encode(['reason' => 'wallet_capacity']), 'created_at' => now(), 'updated_at' => now()]);
    app(RecordPaymentAttentionCaseAction::class)->importHistorical($callback, 'wallet_capacity', null);
    expect(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    $before = $callback->fresh()->getRawOriginal();
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    app(BackfillPaymentAttentionCasesAction::class)->execute();
    expect(ReconciliationCase::sole()->status)->toBe('open')->and(ReconciliationCase::sole()->source_snapshot)->toBeNull()
        ->and(ReconciliationEntry::count())->toBe(1)
        ->and(FinancialTransaction::count())->toBe(0)->and($callback->fresh()->getRawOriginal())->toBe($before)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
});

it('rejects an unrelated credit as a closed account refund source and rolls back the projection', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id]);
    $credit = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_IN,
        'amount' => '10.00', 'reference_key' => 'unrelated:credit', 'meta' => ['mode' => 'refund_wallet', 'currency' => 'CNY']]);
    expect(fn () => DB::transaction(fn () => app(RecordPaymentAttentionCaseAction::class)->refundCredit($owner, $credit)))
        ->toThrow(DomainException::class);
    expect(ReconciliationCase::count())->toBe(0)->and(ReconciliationEntry::count())->toBe(0);
});

it('invalidates a source whose underlying callback identity changed', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'SOURCE-TAMPER', $invoice->invoice_no, '5.00', [], 'success'));
    $case = ReconciliationCase::sole();
    DB::table('payment_callbacks')->where('id', $case->payment_callback_id)->update(['amount' => '6.00']);
    expect(fn () => app(PaymentAttentionCases::class)->assertSource($case))->toThrow(DomainException::class);
    expect(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
});
