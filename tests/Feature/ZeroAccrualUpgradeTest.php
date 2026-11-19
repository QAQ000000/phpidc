<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
});

function zeroAccrualCompletionMigration(): Migration
{
    return require database_path('migrations/2026_11_19_000017_add_zero_affiliate_accrual_completion.php');
}

function legacyZeroAccrualInvoice(): Invoice
{
    $referrer = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '0.04', 'gateway_amount' => '0.04', 'paid_at' => now()->subDay(),
        'line_items' => ['affiliate' => ['referrer_id' => $referrer->id, 'rate' => '10.00', 'freeze_days' => 7]]]);
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION,
        'subject_type' => Invoice::class, 'subject_id' => $invoice->id,
        'reason' => 'The paid invoice commission rounds to zero; no commission is payable.',
        'after' => json_encode(['invoice_amount' => '0.04', 'rate' => '10.00', 'amount' => '0.00', 'referrer_id' => $referrer->id], JSON_THROW_ON_ERROR),
        'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);

    return $invoice;
}

it('adds zero accrual completion on an empty old schema and can rerun without business effects', function (): void {
    $migration = zeroAccrualCompletionMigration();
    $migration->down();
    expect(Schema::hasColumn('invoices', 'affiliate_zero_accrual_at'))->toBeFalse();
    $migration->up();
    $migration->up();
    expect(Schema::hasColumn('invoices', 'affiliate_zero_accrual_at'))->toBeTrue()
        ->and(Invoice::count())->toBe(0)->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)
        ->and(NotificationEvent::count())->toBe(0)->and(DB::table('audit_logs')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('backfills a complete matching historical zero result without altering its original facts', function (): void {
    $migration = zeroAccrualCompletionMigration();
    $migration->down();
    $invoice = legacyZeroAccrualInvoice();
    $before = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    $audits = DB::table('audit_logs')->get()->toJson();
    $users = DB::table('users')->orderBy('id')->get()->toJson();

    $migration->up();
    $upgraded = (array) DB::table('invoices')->where('id', $invoice->id)->first();
    $completedAt = $upgraded['affiliate_zero_accrual_at'];
    unset($upgraded['affiliate_zero_accrual_at']);
    expect($upgraded)->toBe($before)->and($completedAt)->toBe(now()->subHour()->toDateTimeString())
        ->and(DB::table('audit_logs')->get()->toJson())->toBe($audits)
        ->and(DB::table('users')->orderBy('id')->get()->toJson())->toBe($users)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)->and(NotificationEvent::count())->toBe(0);

    DB::table('audit_logs')->delete();
    $migration->up();
    expect($invoice->fresh()->affiliate_zero_accrual_at->toDateTimeString())->toBe($completedAt)
        ->and(DB::table('audit_logs')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('leaves an incomplete or contradictory historical zero audit uncompleted', function (string $case): void {
    $migration = zeroAccrualCompletionMigration();
    $migration->down();
    $invoice = legacyZeroAccrualInvoice();
    $audit = DB::table('audit_logs')->sole();
    $after = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
    match ($case) {
        'no_audit' => DB::table('audit_logs')->delete(),
        'damaged_json' => DB::table('audit_logs')->update(['after' => '{']),
        'partial_payload' => DB::table('audit_logs')->update(['after' => json_encode(['amount' => '0.00'])]),
        'wrong_invoice_amount' => DB::table('audit_logs')->update(['after' => json_encode([...$after, 'invoice_amount' => '0.03'])]),
        'wrong_rate' => DB::table('audit_logs')->update(['after' => json_encode([...$after, 'rate' => '11.00'])]),
        'wrong_amount' => DB::table('audit_logs')->update(['after' => json_encode([...$after, 'amount' => '0.01'])]),
        'wrong_referrer' => DB::table('audit_logs')->update(['after' => json_encode([...$after, 'referrer_id' => $invoice->user_id])]),
        'wrong_actor' => DB::table('audit_logs')->update(['actor_type' => 'user', 'actor_id' => $invoice->user_id]),
        'wrong_reason' => DB::table('audit_logs')->update(['reason' => 'Unverified legacy assumption']),
        'no_time' => DB::table('audit_logs')->update(['created_at' => null]),
        'before_payment' => DB::table('audit_logs')->update(['created_at' => now()->subDays(2)]),
        'invalid_time' => DB::table('audit_logs')->update(['created_at' => 'not-a-time']),
        'conflicting_duplicate' => DB::table('audit_logs')->insert([...array_diff_key((array) $audit, ['id' => true]), 'after' => json_encode([...$after, 'amount' => '0.01'])]),
    };
    $history = DB::table('audit_logs')->orderBy('id')->get()->toJson();
    $migration->up();
    expect($invoice->fresh()->affiliate_zero_accrual_at)->toBeNull()
        ->and(DB::table('audit_logs')->orderBy('id')->get()->toJson())->toBe($history)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)->and(NotificationEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['no_audit', 'damaged_json', 'partial_payload', 'wrong_invoice_amount', 'wrong_rate', 'wrong_amount', 'wrong_referrer', 'wrong_actor',
    'wrong_reason', 'no_time', 'before_payment', 'invalid_time', 'conflicting_duplicate']);

it('requires eligible original invoice facts and an actually zero amount for historical conversion', function (string $case): void {
    $migration = zeroAccrualCompletionMigration();
    $migration->down();
    $invoice = legacyZeroAccrualInvoice();
    $snapshot = $invoice->line_items['affiliate'];
    match ($case) {
        'unpaid' => $invoice->update(['status' => 'Unpaid']),
        'refunded' => $invoice->update(['status' => 'Refunded']),
        'renewal' => $invoice->update(['type' => 'renew']),
        'foreign' => $invoice->update(['currency' => 'USD']),
        'no_paid_at' => $invoice->update(['paid_at' => null]),
        'zero_invoice' => $invoice->update(['total_amount' => '0.00']),
        'nonzero_commission' => (function () use ($invoice): void {
            $invoice->update(['total_amount' => '0.05']);
            $audit = DB::table('audit_logs')->sole();
            $after = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
            DB::table('audit_logs')->update(['after' => json_encode([...$after, 'invoice_amount' => '0.05'])]);
        })(),
        'self_referral' => $invoice->update(['line_items' => ['affiliate' => [...$snapshot, 'referrer_id' => $invoice->user_id]]]),
        'invalid_snapshot_rate' => $invoice->update(['line_items' => ['affiliate' => [...$snapshot, 'rate' => 'invalid']]]),
        'oversized_snapshot_rate' => $invoice->update(['line_items' => ['affiliate' => [...$snapshot, 'rate' => '100.01']]]),
        'incomplete_snapshot' => $invoice->update(['line_items' => ['affiliate' => ['referrer_id' => $snapshot['referrer_id']]]]),
        'damaged_snapshot' => DB::table('invoices')->where('id', $invoice->id)->update(['line_items' => '{']),
        'existing_commission' => AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id, 'referrer_id' => $snapshot['referrer_id']]),
    };
    $commissions = AffiliateCommission::count();
    $migration->up();
    expect(DB::table('invoices')->where('id', $invoice->id)->value('affiliate_zero_accrual_at'))->toBeNull()
        ->and(AffiliateCommission::count())->toBe($commissions)->and(FinancialTransaction::count())->toBe(0)->and(NotificationEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['unpaid', 'refunded', 'renewal', 'foreign', 'no_paid_at', 'zero_invoice', 'nonzero_commission', 'self_referral',
    'invalid_snapshot_rate', 'oversized_snapshot_rate', 'incomplete_snapshot', 'damaged_snapshot', 'existing_commission']);

it('resumes historical conversion after a failed update without replacing completed rows', function (): void {
    $migration = zeroAccrualCompletionMigration();
    $migration->down();
    $first = legacyZeroAccrualInvoice();
    $second = legacyZeroAccrualInvoice();
    $active = true;
    DB::listen(function ($query) use ($second, &$active): void {
        if ($active && str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'affiliate_zero_accrual_at')
            && (int) ($query->bindings[1] ?? 0) === $second->id) {
            throw new RuntimeException('Isolated historical zero update failure');
        }
    });
    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Isolated historical zero update failure');
    } finally {
        $active = false;
    }
    $completed = $first->fresh()->affiliate_zero_accrual_at->toDateTimeString();
    expect($second->fresh()->affiliate_zero_accrual_at)->toBeNull();
    $migration->up();
    expect($first->fresh()->affiliate_zero_accrual_at->toDateTimeString())->toBe($completed)
        ->and($second->fresh()->affiliate_zero_accrual_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->count())->toBe(2)
        ->and(AffiliateCommission::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0)->and(NotificationEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});
