<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\ReviewReceivableEvidenceAction;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Jobs\RunReceivableCheckJob;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Services\ReceivableAdmission;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableSnapshot;
use App\Support\ReceivableRevision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function admissionEvidenceFixture(): array
{
    $owner = User::factory()->create(['affiliate_balance' => '20.00']);
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::REFUNDED, 'currency' => 'CNY']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id,
        'referrer_id' => $owner->id, 'status' => 'clawed_back', 'amount' => '10.00']);
    $root = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id,
        'type' => TransactionType::AFFILIATE_CLAWBACK, 'amount' => '10.00', 'balance_after' => '0.00', 'frozen_after' => '0.00',
        'reference_key' => 'clawback:'.$commission->id, 'meta' => ['currency' => 'CNY', 'commission_id' => $commission->id,
            'previous_status' => 'available', 'receivable_amount' => '10.00', 'recovered_amount' => '0.00']]);
    $actor = User::factory()->create();
    foreach (['finance-operator', 'finance-receivable', 'finance-receivable-reviewer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated strict evidence acceptance');
    }

    return [$owner, $root, $actor];
}

it('streams bounded evidence chunks while retaining exact full root totals and malformed ledger blocking', function (): void {
    [$owner, $root, $actor] = admissionEvidenceFixture();
    config(['receivables.root_chunk_size' => 1, 'receivables.evidence_chunk_size' => 2]);
    for ($index = 0; $index < 5; $index++) {
        $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, [
            'request_id' => (string) Str::ulid(), 'amount' => '2.00', 'source' => 'external_received',
            'external_channel' => 'bank', 'external_reference' => 'BOUNDED-'.Str::ulid(), 'received_at' => now()->subMinute()->toDateTimeString(),
            'reason' => 'Verified original receipt', 'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
        ]);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from "affiliate_receivable_settlements"') && str_contains($query->sql, 'order by')) {
            $queries[] = $query->sql;
        }
    });
    $scan = app(ReceivableSnapshot::class)->read($owner->id, 90, [$root->id]);
    expect($scan['valid'])->toBeTrue()->and($scan['has_outstanding'])->toBeFalse()
        ->and($scan['totals']['settled'])->toBe('10.00')->and($scan['projections'][$root->id]['remaining'])->toBe('0.00')
        ->and($queries)->toHaveCount(4);
    foreach ($queries as $sql) {
        expect($sql)->toContain('limit 2');
    }
    // Deliberate isolated corruption: a claimed receipt must never turn into an unchecked aggregate.
    DB::table('financial_transactions')->where('id', $entry->ledger_transaction_id)->update(['amount' => '1.99']);
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($owner, '1.00', (string) Str::ulid()))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->receivable_reconciliation_required)->toBeTrue()->and($owner->fresh()->receivable_revision)->toBe(6);
});

it('recomputes revision conflicts only after the original financial transaction has rolled back', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '3.00']);
    $reader = new ReceivableSnapshot;
    $reads = 0;
    $baseLevel = DB::transactionLevel();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader, &$reads, $baseLevel): array {
        expect(DB::transactionLevel())->toBe($baseLevel);
        $scan = $reader->read($id, $seconds, $captures);
        if ($reads++ === 0) {
            DB::transaction(fn () => app(ReceivableRevision::class)->bump(User::whereKey($id)->lockForUpdate()->firstOrFail()));
        }

        return $scan;
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '1.00', (string) Str::ulid());
    expect($withdrawal->status)->toBe('pending')->and($reads)->toBe(2);
});

it('reuses completed long verification only for its original request and unchanged evidence', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '3.00']);
    $intent = ['amount' => '1.00', 'destination' => 'wallet'];
    $id = (string) Str::ulid();
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent($user->id, 'affiliate-wallet-request', $id, ['intent' => $intent, 'capture_ids' => []]);
    $requests->put($user->id, 'affiliate-wallet-request', $id, $hmac, ['status' => 'queued']);
    (new RunReceivableCheckJob($user->id, 'affiliate-wallet-request', $id, $hmac))->handle($requests, app(ReceivableSnapshot::class));
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldNotReceive('read');
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '1.00', $id);
    expect($withdrawal->status)->toBe('pending');
    expect(fn () => app(ReceivableAdmission::class)->prepare($user, 'affiliate-wallet-request', $id, ['amount' => '2.00', 'destination' => 'wallet']))
        ->toThrow(ValidationException::class);
    DB::transaction(fn () => app(ReceivableRevision::class)->bump(User::whereKey($user->id)->lockForUpdate()->firstOrFail()));
    expect(fn () => app(ReceivableAdmission::class)->prepare($user, 'affiliate-wallet-request', $id, $intent))->toThrow(ValidationException::class);
    Queue::assertPushed(RunReceivableCheckJob::class, 1);
});

it('requires separate review authority and complete valid evidence before clearing a persistent flag exactly once', function (): void {
    [$owner, $root, $actor] = admissionEvidenceFixture();
    $owner->forceFill(['receivable_reconciliation_required' => true])->save();
    $input = ['request_id' => (string) Str::ulid(), 'reason' => 'Authorized full original evidence review', 'confirm' => '1'];
    expect(fn () => app(ReviewReceivableEvidenceAction::class)->execute($owner, User::factory()->create(), $input))
        ->toThrow(AuthorizationException::class);
    app(ReviewReceivableEvidenceAction::class)->execute($owner, $actor, $input);
    app(ReviewReceivableEvidenceAction::class)->execute($owner, $actor, $input);
    expect($owner->fresh()->receivable_reconciliation_required)->toBeFalse()->and($owner->fresh()->receivable_revision)->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'affiliate.receivable_reviewed')->count())->toBe(1);
    $owner->forceFill(['receivable_reconciliation_required' => true])->save();
    DB::table('financial_transactions')->where('id', $root->id)->update(['amount' => '9.00']);
    expect(fn () => app(ReviewReceivableEvidenceAction::class)->execute($owner, $actor, array_replace($input, ['request_id' => (string) Str::ulid()])))
        ->toThrow(ValidationException::class);
    expect($owner->fresh()->receivable_reconciliation_required)->toBeTrue();
});

it('limits status access to the original identity and authorized reviewers without disclosing original intent', function (): void {
    [$owner, , $actor] = admissionEvidenceFixture();
    $requests = app(ReceivableCheckRequests::class);
    $id = (string) Str::ulid();
    $hmac = $requests->intent($owner->id, 'wallet-clearance-request', $id, ['account' => 'PRIVATE-ACCOUNT']);
    $requests->put($owner->id, 'wallet-clearance-request', $id, $hmac, ['status' => 'queued']);
    $url = $requests->statusUrl($owner->id, 'wallet-clearance-request', $id);
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    $this->actingAs($owner)->get($url)->assertOk()->assertDontSee('PRIVATE-ACCOUNT')->assertDontSee($hmac);
    $this->actingAs($actor)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});

it('renders three and twenty receivable roots using one complete summary and a bounded detail page', function (int $count): void {
    $owner = User::factory()->create();
    for ($index = 0; $index < $count; $index++) {
        // Existing zero-principal cancellation evidence remains a completed non-debt fact.
        FinancialTransaction::create(['user_id' => $owner->id, 'type' => TransactionType::AFFILIATE_CLAWBACK,
            'amount' => '0.00', 'balance_after' => '0.00', 'frozen_after' => '0.00', 'reference_key' => 'zero-review:'.Str::ulid(),
            'meta' => ['receivable_amount' => '0.00', 'recovered_amount' => '0.00']]);
    }
    $this->actingAs($owner)->get('/console/affiliate')->assertOk()
        ->assertViewHas('receivableRoots', fn ($roots): bool => $roots->count() === min(20, $count))
        ->assertViewHas('receivableTotals', fn (array $totals): bool => $totals['roots'] === $count && $totals['remaining'] === '0.00')
        ->assertSee('全部应收：本金 0.00');
    if ($count > 20) {
        $this->get('/console/affiliate?receivables_page=2')->assertOk()
            ->assertViewHas('receivableRoots', fn ($roots): bool => $roots->count() === 1)
            ->assertViewHas('receivableTotals', fn (array $totals): bool => $totals['roots'] === $count);
    }
})->with([3, 20, 21]);

it('keeps financial capture narrow while allowing the twenty displayed roots', function (): void {
    $owner = User::factory()->create();
    expect(fn () => app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', 'original', [], false, false, [1, 2, 3]))
        ->toThrow(LogicException::class);
});

it('retains invalid long-history results as a blocked conclusion until evidence is repaired and reviewed', function (): void {
    [$owner, $root] = admissionEvidenceFixture();
    DB::table('financial_transactions')->where('id', $root->id)->update(['amount' => '9.99']);
    $requests = app(ReceivableCheckRequests::class);
    $id = (string) Str::ulid();
    $intent = ['amount' => '1.00', 'destination' => 'wallet'];
    $hmac = $requests->intent($owner->id, 'affiliate-wallet-request', $id, ['intent' => $intent, 'capture_ids' => []]);
    $requests->put($owner->id, 'affiliate-wallet-request', $id, $hmac, ['status' => 'queued']);
    (new RunReceivableCheckJob($owner->id, 'affiliate-wallet-request', $id, $hmac))->handle($requests, app(ReceivableSnapshot::class));
    expect($requests->get($owner->id, 'affiliate-wallet-request', $id)['status'])->toBe('invalid')
        ->and($owner->fresh()->receivable_reconciliation_required)->toBeTrue();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldNotReceive('read');
    app()->instance(ReceivableSnapshot::class, $snapshot);
    expect(fn () => app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', $id, $intent))
        ->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
});
