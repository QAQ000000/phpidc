<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\RunMaintenanceBatchAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function zeroAccrualInvoice(string $amount = '0.01'): Invoice
{
    $referrer = User::factory()->create(['password' => 'Original-Password-123']);

    return Invoice::factory()->paid()->create(['total_amount' => $amount, 'gateway_amount' => $amount,
        'line_items' => ['affiliate' => ['referrer_id' => $referrer->id, 'rate' => '10.00', 'freeze_days' => 7]]]);
}

it('records zero accrual completion without changing its quote money or notification facts', function (): void {
    $invoice = zeroAccrualInvoice();
    $quote = $invoice->line_items;
    $users = User::orderBy('id')->get()->map->getAttributes()->all();

    expect(app(AccrueAffiliateCommissionAction::class)->execute($invoice->id))->toBeNull()
        ->and($invoice->fresh()->affiliate_zero_accrual_at)->not->toBeNull()
        ->and($invoice->fresh()->affiliate_zero_accrual_at->equalTo(now()))->toBeTrue()
        ->and($invoice->fresh()->line_items)->toBe($quote)
        ->and($invoice->fresh()->total_amount)->toBe('0.01')
        ->and(User::orderBy('id')->get()->map->getAttributes()->all())->toBe($users)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(1);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps zero accrual replay and maintenance exclusion after audit archival and cursor loss', function (): void {
    $invoice = zeroAccrualInvoice();
    $action = app(AccrueAffiliateCommissionAction::class);
    $action->execute($invoice->id);
    $completed = $invoice->fresh()->getAttributes();
    DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->delete();
    Cache::flush();

    expect($action->execute($invoice->id))->toBeNull()->and($action->execute($invoice->id))->toBeNull();
    $batch = app(RunMaintenanceBatchAction::class)->execute('affiliate-accrue');
    expect($batch['selected'])->toBe(0)->and($batch['attempted'])->toBe(0)
        ->and($invoice->fresh()->getAttributes())->toBe($completed)
        ->and(DB::table('audit_logs')->count())->toBe(0)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('uses zero accrual business completion for closure after the audit has been archived', function (): void {
    $invoice = zeroAccrualInvoice();
    $referrer = User::findOrFail($invoice->line_items['affiliate']['referrer_id']);
    expect(app(AccountClosureEligibility::class)->forUser($referrer)['reasons'])->toHaveKey('commissions');

    app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
    $completion = $invoice->fresh()->getAttributes();
    DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->delete();
    expect(app(AccountClosureEligibility::class)->forUser($referrer))->toBe(['eligible' => true, 'reasons' => []]);

    $closed = app(CloseCustomerAccountAction::class)->execute($referrer, 'Original-Password-123', true);
    expect($closed->trashed())->toBeTrue()->and($invoice->fresh()->getAttributes())->toBe($completion)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(0);
});

it('does not let a current audit-only row substitute for zero accrual business completion', function (): void {
    $invoice = zeroAccrualInvoice();
    $referrer = User::findOrFail($invoice->line_items['affiliate']['referrer_id']);
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION,
        'subject_type' => Invoice::class, 'subject_id' => $invoice->id, 'created_at' => now(), 'updated_at' => now()]);

    expect($invoice->fresh()->affiliate_zero_accrual_at)->toBeNull()
        ->and(app(AccountClosureEligibility::class)->forUser($referrer)['reasons'])->toHaveKey('commissions');
    $batch = app(RunMaintenanceBatchAction::class)->execute('affiliate-accrue');
    expect($batch['succeeded'])->toBe(1)->and($invoice->fresh()->affiliate_zero_accrual_at)->not->toBeNull()
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe(0);
});

it('rolls back zero completion and its audit together when either write fails', function (string $failure): void {
    $invoice = zeroAccrualInvoice();
    $before = $invoice->fresh()->getAttributes();
    $active = true;
    DB::listen(function ($query) use ($failure, &$active): void {
        $sql = strtolower($query->sql);
        if ($active && (($failure === 'audit' && str_contains($sql, 'insert into') && str_contains($sql, 'audit_logs'))
            || ($failure === 'completion' && str_contains($sql, 'update') && str_contains($sql, 'affiliate_zero_accrual_at')))) {
            throw new RuntimeException('Isolated zero accrual write failure');
        }
    });
    try {
        expect(fn () => app(AccrueAffiliateCommissionAction::class)->execute($invoice->id))
            ->toThrow(RuntimeException::class, 'Isolated zero accrual write failure');
    } finally {
        $active = false;
    }
    expect($invoice->fresh()->getAttributes())->toBe($before)
        ->and(DB::table('audit_logs')->where('action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION)->count())->toBe(0)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['audit', 'completion']);

it('preserves the nonzero rounding boundary while recording only zero completion', function (string $amount, string $commission): void {
    $invoice = zeroAccrualInvoice($amount);
    app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
    app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);

    if ($commission === '0.00') {
        expect($invoice->fresh()->affiliate_zero_accrual_at)->not->toBeNull()->and(AffiliateCommission::count())->toBe(0)
            ->and(NotificationEvent::count())->toBe(0);
    } else {
        expect($invoice->fresh()->affiliate_zero_accrual_at)->toBeNull()
            ->and(AffiliateCommission::sole()->amount)->toBe($commission)->and(AffiliateCommission::sole()->status)->toBe('pending')
            ->and(NotificationEvent::where('event_key', 'commission:'.AffiliateCommission::sole()->id.':pending')->count())->toBe(1);
    }
    expect(FinancialTransaction::count())->toBe(0);
})->with([['0.04', '0.00'], ['0.05', '0.01']]);

it('does not mark an ineligible invoice as zero accrual completed', function (string $case): void {
    $invoice = zeroAccrualInvoice();
    match ($case) {
        'unpaid' => $invoice->update(['status' => InvoiceStatus::UNPAID]),
        'refunded' => $invoice->update(['status' => InvoiceStatus::REFUNDED]),
        'renewal' => $invoice->update(['type' => InvoiceType::RENEW]),
        'foreign' => $invoice->update(['currency' => 'USD']),
        'no_paid_at' => $invoice->update(['paid_at' => null]),
        'zero_invoice' => $invoice->update(['total_amount' => '0.00']),
        'self_referral' => $invoice->update(['line_items' => ['affiliate' => ['referrer_id' => $invoice->user_id, 'rate' => '10.00', 'freeze_days' => 7]]]),
        'closed_referrer' => User::findOrFail($invoice->line_items['affiliate']['referrer_id'])->delete(),
    };
    expect(app(AccrueAffiliateCommissionAction::class)->execute($invoice->id))->toBeNull()
        ->and($invoice->fresh()->affiliate_zero_accrual_at)->toBeNull()
        ->and(DB::table('audit_logs')->count())->toBe(0)->and(AffiliateCommission::count())->toBe(0)
        ->and(FinancialTransaction::count())->toBe(0)->and(NotificationEvent::count())->toBe(0);
})->with(['unpaid', 'refunded', 'renewal', 'foreign', 'no_paid_at', 'zero_invoice', 'self_referral', 'closed_referrer']);
