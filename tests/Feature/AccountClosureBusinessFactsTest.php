<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Data\VerifiedPaymentResult;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use App\Services\PaymentAttentionCases;
use App\Support\WalletCapacity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function closureZeroAccrualInvoice(User $referrer): Invoice
{
    return Invoice::factory()->paid()->create(['total_amount' => '0.01', 'gateway_amount' => '0.01',
        'line_items' => ['affiliate' => ['referrer_id' => $referrer->id, 'rate' => '10.00', 'freeze_days' => 7]]]);
}

it('retains zero commission completion and closure replay when its audit is archived', function (): void {
    $owner = User::factory()->create(['password' => 'Closure-Facts-Password-123'])->fresh();
    $invoice = closureZeroAccrualInvoice($owner);
    $originalSnapshot = $invoice->line_items;
    expect(app(AccountClosureEligibility::class)->forUser($owner)['reasons'])->toHaveKey('commissions');

    expect(app(AccrueAffiliateCommissionAction::class)->execute($invoice->id))->toBeNull();
    $completed = $invoice->fresh()->affiliate_zero_accrual_at;
    expect($completed)->not->toBeNull()
        ->and(app(AccountClosureEligibility::class)->forUser($owner)['eligible'])->toBeTrue();
    expect(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)
        ->where('subject_id', $invoice->id)->delete())->toBe(1);

    expect(app(AccountClosureEligibility::class)->forUser($owner)['eligible'])->toBeTrue();
    $closed = app(CloseCustomerAccountAction::class)->execute($owner, 'Closure-Facts-Password-123', true);
    $closedSnapshot = $closed->getRawOriginal();
    expect($closed->trashed())->toBeTrue()->and($closed->anonymized_at)->not->toBeNull()
        ->and(app(CloseCustomerAccountAction::class)->execute($owner, 'irrelevant-completed-replay', true)->getRawOriginal())->toBe($closedSnapshot)
        ->and($invoice->fresh()->line_items)->toBe($originalSnapshot)
        ->and($invoice->fresh()->affiliate_zero_accrual_at->equalTo($completed))->toBeTrue()
        ->and($invoice->fresh()->line_items['affiliate']['referrer_id'])->toBe($owner->id)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps unresolved payment sources blocking closure independently of completed zero accrual and audit retention', function (): void {
    $owner = User::factory()->create(['password' => 'Closure-Facts-Password-123', 'credit' => WalletCapacity::LIMIT])->fresh();
    $promoted = closureZeroAccrualInvoice($owner);
    app(AccrueAffiliateCommissionAction::class)->execute($promoted->id);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $receipt = new VerifiedPaymentResult('epay_wechat', 'CLOSURE-PERSISTENT-ATTENTION', $invoice->invoice_no, '1.00');
    expect(app(HandlePaymentCallbackAction::class)->execute($receipt))->toBe('success');
    $case = ReconciliationCase::sole();
    $callback = PaymentCallback::sole();
    $source = $case->source_snapshot;
    expect($source)->not->toBeNull()->and($case->status)->toBe('open')
        ->and(app(PaymentAttentionCases::class)->assertCallbackSource($case)->id)->toBe($callback->id);
    $before = app(AccountClosureEligibility::class)->forUser($owner);
    expect($before['eligible'])->toBeFalse()->and($before['reasons'])->toHaveKey('payments')->not->toHaveKey('commissions');

    expect(DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_id', $callback->id)->delete())->toBe(1);
    expect(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)
        ->where('subject_id', $promoted->id)->delete())->toBe(1);
    expect(app(AccountClosureEligibility::class)->forUser($owner))->toBe($before)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    expect(fn () => app(CloseCustomerAccountAction::class)->execute($owner, 'Closure-Facts-Password-123', true))
        ->toThrow(ValidationException::class);
    expect(User::find($owner->id))->not->toBeNull()->and($owner->fresh()->anonymized_at)->toBeNull()
        ->and($owner->fresh()->credit)->toBe(WalletCapacity::LIMIT)
        ->and($case->fresh()->source_snapshot)->toBe($source)->and($case->fresh()->revision)->toBe(1)
        ->and(FinancialTransaction::count())->toBe(0)->and(AffiliateCommission::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not let a completed zero accrual hide another pending referral obligation after audit archival', function (string $obligation): void {
    $owner = User::factory()->create(['password' => 'Closure-Facts-Password-123'])->fresh();
    $completedInvoice = closureZeroAccrualInvoice($owner);
    app(AccrueAffiliateCommissionAction::class)->execute($completedInvoice->id);
    $pendingInvoice = Invoice::factory()->paid()->create(['line_items' => ['affiliate' => ['referrer_id' => $owner->id,
        'rate' => '10.00', 'freeze_days' => 7]]]);
    if ($obligation === 'pending_commission') {
        app(AccrueAffiliateCommissionAction::class)->execute($pendingInvoice->id);
        expect(AffiliateCommission::sole()->status)->toBe('pending');
    }
    DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)
        ->where('subject_id', $completedInvoice->id)->delete();

    expect(app(AccountClosureEligibility::class)->forUser($owner)['reasons'])->toHaveKey('commissions');
    expect(fn () => app(CloseCustomerAccountAction::class)->execute($owner, 'Closure-Facts-Password-123', true))
        ->toThrow(ValidationException::class);
    expect(User::find($owner->id))->not->toBeNull()->and($owner->fresh()->anonymized_at)->toBeNull()
        ->and($pendingInvoice->fresh()->affiliate_zero_accrual_at)->toBeNull()
        ->and(FinancialTransaction::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
})->with(['pending_commission', 'unprocessed_invoice']);
