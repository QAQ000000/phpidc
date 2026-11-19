<?php

declare(strict_types=1);

use App\Actions\BackfillPaymentAttentionCasesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\RecordPaymentAttentionCaseAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\PaymentCallbackOutcome;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\PaymentAttentionCases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function paymentSnapshotUpgradeMigration(): Migration
{
    return require database_path('migrations/2026_11_19_000016_add_payment_attention_source_snapshots.php');
}

function paymentSnapshotLegacySource(string $mode = 'complete'): array
{
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    $callback = PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'outcome' => PaymentCallbackOutcome::REJECTED_INVALID_STATE]);
    $facts = ['reason' => 'wallet_capacity', 'outcome' => $callback->outcome->value, 'invoice_id' => $invoice->id, 'amount' => $callback->amount];
    if ($mode === 'partial') {
        $facts = ['reason' => 'wallet_capacity'];
    } elseif ($mode === 'conflict') {
        $facts['amount'] = '5.01';
    }
    $audit = DB::table('audit_logs')->insertGetId(['actor_type' => 'system', 'action' => 'payment.callback.attention',
        'subject_type' => PaymentCallback::class, 'subject_id' => $callback->id, 'reason' => 'wallet_capacity',
        'after' => $mode === 'damaged' ? '{damaged-history' : json_encode($facts, JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now()]);

    return [$owner, $invoice, $callback, $audit];
}

it('registers every old audit-only blocker during the additive migration and survives later audit archival', function (): void {
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    expect(Schema::hasColumn('reconciliation_cases', 'source_snapshot'))->toBeFalse();
    [$owner, $invoice, $callback] = paymentSnapshotLegacySource();
    $original = $callback->fresh()->getRawOriginal();
    $oldInvoice = $invoice->fresh()->getRawOriginal();
    $audits = DB::table('audit_logs')->get()->toJson();
    // This is precisely the legacy audit-only source the former hot-path query treated as blocking.
    expect(DB::table('audit_logs')->where('action', 'payment.callback.attention')->where('subject_id', $callback->id)
        ->where('after->reason', 'wallet_capacity')->exists())->toBeTrue()->and(ReconciliationCase::count())->toBe(0);
    $migration->up();
    $case = ReconciliationCase::sole();
    expect($case->status)->toBe('open')->and($case->revision)->toBe(1)->and(ReconciliationEntry::count())->toBe(1)
        ->and($case->source_snapshot)->toBe(app(PaymentAttentionCases::class)->callbackSourceSnapshot($callback, $invoice, 'wallet_capacity'))
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue()
        ->and($callback->fresh()->getRawOriginal())->toBe($original)->and($invoice->fresh()->getRawOriginal())->toBe($oldInvoice)
        ->and(DB::table('audit_logs')->get()->toJson())->toBe($audits)
        ->and(FinancialTransaction::count())->toBe(0)->and(DB::table('notification_events')->count())->toBe(0);
    $caseRow = $case->getRawOriginal();
    $entries = DB::table('reconciliation_entries')->get()->toJson();
    $migration->up();
    expect(ReconciliationCase::sole()->getRawOriginal())->toBe($caseRow)->and(DB::table('reconciliation_entries')->get()->toJson())->toBe($entries);
    DB::table('audit_logs')->where('action', 'payment.callback.attention')->delete();
    expect(app(PaymentAttentionCases::class)->assertCallbackSource($case)->id)->toBe($callback->id)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    Http::assertNothingSent();
});

it('registers incomplete or damaged legacy evidence as an unresolved source without fabricating proof', function (string $mode): void {
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    [$owner] = paymentSnapshotLegacySource($mode);
    $migration->up();
    $case = ReconciliationCase::sole();
    expect($case->source_snapshot)->toBeNull()->and($case->status)->toBe('open')->and($case->revision)->toBe(1)
        ->and(ReconciliationEntry::count())->toBe(1)->and(FinancialTransaction::count())->toBe(0)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    expect(fn () => app(PaymentAttentionCases::class)->assertCallbackSource($case))->toThrow(DomainException::class);
    $result = app(BackfillPaymentAttentionCasesAction::class)->callbacks();
    expect($result)->toBe(['callbacks' => 1, 'snapshots' => 0, 'missing_evidence' => 1, 'omissions' => 0])
        ->and(ReconciliationEntry::count())->toBe(1)->and(ReconciliationCase::sole()->source_snapshot)->toBeNull();
})->with(['partial', 'damaged']);

it('fills a complete old source without changing an existing resolved case disposition or timestamps', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'LEGACY-ZERO-SNAPSHOT', $invoice->invoice_no, '0.00'));
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    $before = DB::table('reconciliation_cases')->sole();
    $entries = DB::table('reconciliation_entries')->get()->toJson();
    $migration->up();
    $case = ReconciliationCase::sole();
    $after = (array) DB::table('reconciliation_cases')->sole();
    unset($after['source_snapshot']);
    expect($after)->toBe((array) $before)->and($case->source_snapshot)->not->toBeNull()
        ->and($case->status)->toBe('resolved')->and($case->revision)->toBe(2)
        ->and(DB::table('reconciliation_entries')->get()->toJson())->toBe($entries)
        ->and(app(PaymentAttentionCases::class)->isResolved($case))->toBeTrue();
});

it('rejects contradictory legacy receipts and can resume after a partially completed migration', function (): void {
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    [$firstOwner] = paymentSnapshotLegacySource();
    [$secondOwner, , $callback, $auditId] = paymentSnapshotLegacySource('conflict');
    expect(fn () => $migration->up())->toThrow(DomainException::class);
    expect(Schema::hasColumn('reconciliation_cases', 'source_snapshot'))->toBeTrue()
        ->and(ReconciliationCase::count())->toBe(1)->and(ReconciliationEntry::count())->toBe(1)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($firstOwner->id))->toBeTrue();
    // Correct the isolated old evidence, then retry the same migration without dropping its added column.
    DB::table('audit_logs')->where('id', $auditId)->update(['after' => json_encode(['reason' => 'wallet_capacity',
        'outcome' => $callback->outcome->value, 'invoice_id' => $callback->invoice_id, 'amount' => $callback->amount], JSON_THROW_ON_ERROR)]);
    $migration->up();
    expect(ReconciliationCase::count())->toBe(2)->and(ReconciliationEntry::count())->toBe(2)
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($secondOwner->id))->toBeTrue()
        ->and(FinancialTransaction::count())->toBe(0);
});

it('fails the migration when a historical attention has no original financial identity', function (): void {
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'payment.callback.attention', 'subject_type' => PaymentCallback::class,
        'subject_id' => 999999, 'reason' => 'wallet_capacity', 'after' => json_encode(['reason' => 'wallet_capacity']),
        'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => $migration->up())->toThrow(DomainException::class)
        ->and(ReconciliationCase::count())->toBe(0)->and(FinancialTransaction::count())->toBe(0);
});

it('does not declare an upgrade complete when a historical source was skipped', function (): void {
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    paymentSnapshotLegacySource();
    $this->mock(RecordPaymentAttentionCaseAction::class)->shouldReceive('importHistorical')->once()->andReturn(new ReconciliationCase);
    expect(fn () => $migration->up())->toThrow(DomainException::class, 'unregistered historical sources')
        ->and(ReconciliationCase::count())->toBe(0)->and(ReconciliationEntry::count())->toBe(0);
});

it('never overwrites an already stored source snapshot that conflicts with its original receipt', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'LEGACY-CONFLICT-SNAPSHOT', $invoice->invoice_no, '1.00'));
    $case = ReconciliationCase::sole();
    $damaged = [...$case->source_snapshot, 'amount' => '1.01'];
    $case->update(['source_snapshot' => $damaged]);
    $before = $case->fresh()->getRawOriginal();
    expect(fn () => paymentSnapshotUpgradeMigration()->up())->toThrow(DomainException::class);
    expect($case->fresh()->getRawOriginal())->toBe($before)->and(ReconciliationEntry::count())->toBe(1);
});

it('keeps a formerly resolved zero callback blocked when historical source proof is missing', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $invoice = Invoice::factory()->expired()->create(['user_id' => $owner->id, 'gateway' => 'epay_wechat']);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'LEGACY-ZERO-MISSING-PROOF', $invoice->invoice_no, '0.00'));
    $migration = paymentSnapshotUpgradeMigration();
    $migration->down();
    DB::table('audit_logs')->where('action', 'payment.callback.attention')->update(['after' => json_encode(['reason' => 'closed_account'])]);
    $before = DB::table('reconciliation_cases')->sole();
    $entries = DB::table('reconciliation_entries')->get()->toJson();
    $migration->up();
    $case = ReconciliationCase::sole();
    $after = (array) DB::table('reconciliation_cases')->sole();
    unset($after['source_snapshot']);
    expect($after)->toBe((array) $before)->and($case->source_snapshot)->toBeNull()
        ->and(DB::table('reconciliation_entries')->get()->toJson())->toBe($entries)
        ->and(app(PaymentAttentionCases::class)->isResolved($case))->toBeFalse()
        ->and(app(PaymentAttentionCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
});
