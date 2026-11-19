<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\MatureAffiliateCommissionsAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Actions\ReviewReceivableEvidenceAction;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Jobs\AuditReceivableEvidenceJob;
use App\Jobs\RunReceivableCheckJob;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Services\ReceivableAdmission;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableReconciliation;
use App\Services\ReceivableSnapshot;
use App\Services\ReceivableVerificationCache;
use App\Support\ReceivableRevision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    config(['receivables.cache_store' => 'array']);
    Queue::fake();
    Http::preventStrayRequests();
});

function verificationDebtFixture(): array
{
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::REFUNDED, 'currency' => 'CNY']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id,
        'referrer_id' => $owner->id, 'status' => 'clawed_back', 'amount' => '10.00']);
    $root = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id,
        'type' => TransactionType::AFFILIATE_CLAWBACK, 'amount' => '10.00', 'balance_after' => '0.00', 'frozen_after' => '0.00',
        'reference_key' => 'clawback:'.$commission->id, 'meta' => ['currency' => 'CNY', 'commission_id' => $commission->id,
            'previous_status' => 'available', 'receivable_amount' => '10.00', 'recovered_amount' => '0.00']]);

    return [$owner, $root];
}

it('shares one encrypted completed scan across ordinary requests while binding each original intent', function (): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $first = $admission->prepare($owner, 'wallet-clearance-request', 'original-one', ['account' => 'PRIVATE-ACCOUNT', 'amount' => '13.57']);
    $second = $admission->prepare($owner, 'affiliate-wallet-request', 'original-two', ['amount' => '3.21']);
    expect($second)->toBe($first);
    $requests = app(ReceivableCheckRequests::class);
    expect($requests->get($owner->id, 'wallet-clearance-request', 'original-one')['status'])->toBe('ready')
        ->and($requests->get($owner->id, 'affiliate-wallet-request', 'original-two')['status'])->toBe('ready');
    expect(fn () => $admission->prepare($owner, 'affiliate-wallet-request', 'original-two', ['amount' => '9.99']))
        ->toThrow(ValidationException::class);
    $key = app(ReceivableVerificationCache::class)->key($owner->id, '0');
    $encrypted = Cache::store('array')->get($key);
    expect($encrypted)->toBeString()->not->toContain('PRIVATE-ACCOUNT', '13.57', 'revision', 'digest');
    $proof = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
    expect($proof['scan'])->toBe($first)->and($proof['expires_at'] - $proof['started_at'])->toBe(600)
        ->and(Crypt::decryptString($encrypted))->not->toContain('PRIVATE-ACCOUNT', '13.57', '3.21');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('reuses a foreground captured result only for its original request and rescans new captured requests', function (): void {
    [$owner, $root] = verificationDebtFixture();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->with($owner->id, 2, [$root->id])
        ->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $first = $admission->prepare($owner, 'receivable-view', 'page-original', [], false, false, [$root->id]);
    $repeat = $admission->prepare($owner, 'receivable-view', 'page-original', [], false, false, [$root->id]);
    $other = $admission->prepare($owner, 'receivable-view', 'page-next-request', [], false, false, [$root->id]);
    expect($repeat)->toBe($first)->and($other['projections'][$root->id]['remaining'])->toBe('10.00');
    $proof = app(ReceivableVerificationCache::class)->get($owner->fresh());
    expect($proof['scan']['projections'])->toBe([])->and($first['projections'])->toHaveCount(1);
});

it('rescans legacy ready results that cannot prove their original scan start expiry', function (): void {
    $owner = User::factory()->create();
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent($owner->id, 'wallet-clearance-request', 'legacy-original', ['intent' => [], 'capture_ids' => []]);
    $reader = new ReceivableSnapshot;
    $requests->put($owner->id, 'wallet-clearance-request', 'legacy-original', $hmac,
        ['status' => 'ready', 'completed_at' => now()->toIso8601String(), 'scan' => $reader->read($owner->id, 2)]);
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    app(ReceivableAdmission::class)->prepare($owner, 'wallet-clearance-request', 'legacy-original', []);
    $state = $requests->get($owner->id, 'wallet-clearance-request', 'legacy-original');
    expect($state['status'])->toBe('ready')->and($state['expires_at'] - $state['scan_started_at'])->toBe(600);
    Queue::assertNothingPushed();
});

it('expires foreground and shared permissions from scan start without renewing on hits', function (): void {
    $this->travelTo(now()->startOfSecond());
    $start = now()->toImmutable();
    config(['receivables.result_ttl_seconds' => 1200]);
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $reads = 0;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader, &$reads): array {
        $reads++;
        $scan = $reader->read($id, $seconds, $captures);
        $this->travel(40)->seconds();

        return $scan;
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $admission->prepare($owner, 'wallet-clearance-request', 'original', []);
    $verification = app(ReceivableVerificationCache::class);
    $key = $verification->key($owner->id, '0');
    $encrypted = Cache::store('array')->get($key);
    expect($verification->get($owner->fresh())['expires_at'])->toBe($start->addSeconds(600)->getTimestamp());
    $this->travelTo($start->addSeconds(300));
    $admission->prepare($owner, 'affiliate-wallet-request', 'another', []);
    $this->travelTo($start->addSeconds(599));
    $admission->prepare($owner, 'affiliate-wallet-request', 'another', []);
    expect(Cache::store('array')->get($key))->toBe($encrypted)->and($reads)->toBe(1);
    $this->travelTo($start->addSeconds(600));
    expect(app(ReceivableCheckRequests::class)->get($owner->id, 'wallet-clearance-request', 'original'))->toBeNull()
        ->and(app(ReceivableCheckRequests::class)->get($owner->id, 'affiliate-wallet-request', 'another'))->toBeNull()
        ->and($verification->get($owner->fresh()))->toBeNull();
    $admission->prepare($owner, 'wallet-clearance-request', 'original', []);
    expect($reads)->toBe(2);
});

it('binds shared conclusions to the original user revision algorithm and application key', function (string $change): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $admission->prepare($owner, 'wallet-clearance-request', 'first', []);
    match ($change) {
        'user' => $owner = User::factory()->create(),
        'revision' => DB::transaction(fn () => app(ReceivableRevision::class)->bump(User::whereKey($owner->id)->lockForUpdate()->firstOrFail())),
        'algorithm' => config(['receivables.algorithm_version' => 2]),
        'key' => config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]),
    };
    $scan = $admission->prepare($owner, 'affiliate-wallet-request', 'second', []);
    expect($scan['user_id'])->toBe($owner->id)->and($scan['revision'])->toBe((string) $owner->fresh()->receivable_revision)
        ->and($scan['algorithm'])->toBe((int) config('receivables.algorithm_version'));
})->with(['user', 'revision', 'algorithm', 'key']);

it('falls back to a full scan for missing corrupt incomplete expired or mismatched encrypted proof', function (string $corruption): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $admission->prepare($owner, 'wallet-clearance-request', 'first', []);
    $key = app(ReceivableVerificationCache::class)->key($owner->id, '0');
    $proof = json_decode(Crypt::decryptString(Cache::store('array')->get($key)), true, 512, JSON_THROW_ON_ERROR);
    match ($corruption) {
        'digest' => $proof['scan']['digest'] = '',
        'totals' => $proof['scan']['totals'] = ['roots' => 0],
        'user' => $proof['scan']['user_id']++,
        'revision' => $proof['scan']['revision'] = '999',
        'algorithm' => $proof['scan']['algorithm']++,
        'key' => $proof['key_version'] = 'unrelated-key',
        'flag' => $proof['scan']['reconciliation_required'] = true,
        'invalid' => $proof['scan']['valid'] = false,
        'expired' => $proof['expires_at'] = now()->subSecond()->getTimestamp(),
        'future' => $proof['started_at'] = now()->addSecond()->getTimestamp(),
        'extended' => $proof['expires_at'] = $proof['started_at'] + 601,
        'captures' => $proof['scan']['projections'] = ['untrusted' => []],
        default => null,
    };
    if ($corruption === 'missing') {
        Cache::store('array')->forget($key);
    } else {
        Cache::store('array')->put($key, $corruption === 'ciphertext' ? 'broken-ciphertext' : Crypt::encryptString(json_encode($proof, JSON_THROW_ON_ERROR)), 600);
    }
    expect($admission->prepare($owner, 'affiliate-wallet-request', 'second', [])['valid'])->toBeTrue();
})->with(['missing', 'ciphertext', 'digest', 'totals', 'user', 'revision', 'algorithm', 'key', 'flag', 'invalid', 'expired', 'future', 'extended', 'captures']);

it('blocks a newly set persistent flag before using a cached ordinary conclusion', function (): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    $admission->prepare($owner, 'wallet-clearance-request', 'first', []);
    $owner->forceFill(['receivable_reconciliation_required' => true])->save();
    expect(fn () => $admission->prepare($owner, 'affiliate-wallet-request', 'second', []))->toThrow(ValidationException::class)
        ->and(app(ReceivableVerificationCache::class)->get($owner->fresh()))->toBeNull();
    Queue::assertNothingPushed();
});

it('does not publish a foreground scan when its revision or flag drifted during the scan', function (string $change): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader, $change): array {
        $scan = $reader->read($id, $seconds, $captures);
        DB::transaction(function () use ($id, $change): void {
            $locked = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($change === 'revision') {
                app(ReceivableRevision::class)->bump($locked);
            } else {
                $locked->forceFill(['receivable_reconciliation_required' => true])->save();
            }
        });

        return $scan;
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);
    app(ReceivableAdmission::class)->prepare($owner, 'wallet-clearance-request', 'original', []);
    expect(app(ReceivableCheckRequests::class)->get($owner->id, 'wallet-clearance-request', 'original'))->toBeNull()
        ->and(app(ReceivableVerificationCache::class)->get($owner))->toBeNull();
})->with(['revision', 'flag']);

it('retries its own cached permit after a lock conflict outside the rolled back financial transaction', function (): void {
    $owner = User::factory()->create();
    $reader = new ReceivableSnapshot;
    $reads = 0;
    $baseLevel = DB::transactionLevel();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader, &$reads, $baseLevel): array {
        expect(DB::transactionLevel())->toBe($baseLevel);
        $reads++;

        return $reader->read($id, $seconds, $captures);
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $attempts = 0;
    $admission = app(ReceivableAdmission::class);
    $result = $admission->run($owner, 'wallet-clearance-request', 'original', [], function (array $permit) use ($owner, &$attempts, $admission): string {
        if ($attempts++ === 0) {
            DB::transaction(fn () => app(ReceivableRevision::class)->bump(User::whereKey($owner->id)->lockForUpdate()->firstOrFail()));
        }

        return DB::transaction(function () use ($owner, $permit, $admission): string {
            $admission->assertLocked(User::whereKey($owner->id)->lockForUpdate()->firstOrFail(), $permit);

            return 'accepted-current-revision';
        });
    });
    expect($result)->toBe('accepted-current-revision')->and($reads)->toBe(2)->and($attempts)->toBe(2)
        ->and(app(ReceivableCheckRequests::class)->get($owner->id, 'wallet-clearance-request', 'original')['scan']['revision'])->toBe('1');
    Queue::assertNothingPushed();
});

it('lets a completed background full scan seed a different ordinary request without sharing captured roots', function (): void {
    [$owner, $root] = verificationDebtFixture();
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent($owner->id, 'receivable-view', 'queued-original', ['intent' => [], 'capture_ids' => [$root->id]]);
    $requests->put($owner->id, 'receivable-view', 'queued-original', $hmac, ['status' => 'queued', 'capture_ids' => [$root->id]]);
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->with($owner->id, 90, [$root->id])
        ->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    (new RunReceivableCheckJob($owner->id, 'receivable-view', 'queued-original', $hmac))->handle($requests, $snapshot);
    $scan = app(ReceivableAdmission::class)->prepare($owner, 'wallet-clearance-request', 'new-original', [], false);
    expect($scan['has_outstanding'])->toBeTrue()->and($scan['projections'])->toBe([])
        ->and($requests->get($owner->id, 'receivable-view', 'queued-original')['scan']['projections'])->toHaveCount(1);
});

it('keeps background original and new request hits within the background scan start expiry', function (): void {
    $this->travelTo(now()->startOfSecond());
    $owner = User::factory()->create();
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent($owner->id, 'wallet-clearance-request', 'queued-original', ['intent' => [], 'capture_ids' => []]);
    $requests->put($owner->id, 'wallet-clearance-request', 'queued-original', $hmac, ['status' => 'queued']);
    $this->travel(100)->seconds();
    $start = now()->toImmutable();
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader): array {
        $scan = $reader->read($id, $seconds, $captures);
        $this->travel(60)->seconds();

        return $scan;
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);
    (new RunReceivableCheckJob($owner->id, 'wallet-clearance-request', 'queued-original', $hmac))->handle($requests, $snapshot);
    $verification = app(ReceivableVerificationCache::class);
    $key = $verification->key($owner->id, '0');
    $encrypted = Cache::store('array')->get($key);
    expect($verification->get($owner->fresh())['expires_at'])->toBe($start->addSeconds(600)->getTimestamp());
    $this->travelTo($start->addSeconds(599));
    app(ReceivableAdmission::class)->prepare($owner, 'wallet-clearance-request', 'queued-original', []);
    app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', 'another-original', []);
    expect(Cache::store('array')->get($key))->toBe($encrypted);
    $this->travelTo($start->addSeconds(600));
    expect($requests->get($owner->id, 'wallet-clearance-request', 'queued-original'))->toBeNull()
        ->and($requests->get($owner->id, 'affiliate-wallet-request', 'another-original'))->toBeNull()
        ->and($verification->get($owner->fresh()))->toBeNull();
});

it('does not turn an outstanding or invalid conclusion into a money permission', function (): void {
    [$owner, $root] = verificationDebtFixture();
    $admission = app(ReceivableAdmission::class);
    expect(fn () => $admission->prepare($owner, 'wallet-clearance-request', 'first', []))->toThrow(ValidationException::class);
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldNotReceive('read');
    app()->instance(ReceivableSnapshot::class, $snapshot);
    expect(fn () => app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', 'second', []))->toThrow(ValidationException::class);
    expect(app(ReceivableVerificationCache::class)->get($owner->fresh())['scan']['has_outstanding'])->toBeTrue();
    // An independent full audit still detects direct SQL damage inside the approved cache window.
    DB::table('financial_transactions')->where('id', $root->id)->update(['amount' => '9.99']);
    (new AuditReceivableEvidenceJob($owner->id))->handle(new ReceivableSnapshot, app(ReceivableReconciliation::class));
    expect($owner->fresh()->receivable_reconciliation_required)->toBeTrue()
        ->and(app(ReceivableVerificationCache::class)->get($owner->fresh()))->toBeNull();
    expect(fn () => app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', 'third', []))->toThrow(ValidationException::class);
});

it('keeps manual review and independent audit on complete fresh scans even with an ordinary proof', function (): void {
    $owner = User::factory()->create();
    $actor = User::factory()->create();
    foreach (['finance-operator', 'finance-receivable-reviewer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated full review');
    }
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->times(4)->andReturnUsing(fn (int $id, int $seconds, array $captures = []): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    app(ReceivableAdmission::class)->prepare($owner, 'wallet-clearance-request', 'ordinary', []);
    app(ReviewReceivableEvidenceAction::class)->execute($owner, $actor,
        ['request_id' => (string) Str::ulid(), 'reason' => 'Full original evidence review', 'confirm' => '1']);
    app(ReceivableAdmission::class)->prepare($owner, 'affiliate-wallet-request', 'ordinary-after-review', []);
    // The audit is deliberately a fourth read, despite the new revision's cached ordinary conclusion.
    (new AuditReceivableEvidenceJob($owner->id))->handle($snapshot, app(ReceivableReconciliation::class));
    expect($owner->fresh()->receivable_revision)->toBe(1);
});

it('checks no-revision SQL damage after the original proof expires without extending the detection window', function (): void {
    $this->travelTo(now()->startOfSecond());
    [$owner, $root] = verificationDebtFixture();
    $start = now()->toImmutable();
    $admission = app(ReceivableAdmission::class);
    $first = $admission->prepare($owner, 'ordinary-one', 'first', [], false);
    DB::table('financial_transactions')->where('id', $root->id)->update(['amount' => '9.99']);
    $this->travelTo($start->addSeconds(599));
    expect($admission->prepare($owner, 'ordinary-two', 'second', [], false))->toBe($first)
        ->and($owner->fresh()->receivable_reconciliation_required)->toBeFalse();
    $this->travelTo($start->addSeconds(600));
    expect(fn () => $admission->prepare($owner, 'ordinary-three', 'third', [], false))->toThrow(ValidationException::class);
    expect($owner->fresh()->receivable_reconciliation_required)->toBeTrue()
        ->and(app(ReceivableVerificationCache::class)->get($owner->fresh()))->toBeNull();
});

it('invalidates old ordinary conclusions after real settlement and reversal writes', function (): void {
    [$owner, $root] = verificationDebtFixture();
    $actor = User::factory()->create();
    foreach (['finance-operator', 'finance-receivable'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated receivable write');
    }
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->times(5)->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    expect($admission->prepare($owner, 'ordinary-one', 'first', [], false)['has_outstanding'])->toBeTrue();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, [
        'request_id' => (string) Str::ulid(), 'amount' => '10.00', 'source' => 'external_received',
        'external_channel' => 'bank', 'external_reference' => 'ORIGINAL-RECEIPT', 'received_at' => now()->subMinute()->toDateTimeString(),
        'reason' => 'Verified original collection', 'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
    ]);
    expect($admission->prepare($owner, 'ordinary-two', 'second', [])['has_outstanding'])->toBeFalse()
        ->and($owner->fresh()->receivable_revision)->toBe(1);
    app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, [
        'request_id' => (string) Str::ulid(), 'reason' => 'Original collection corrected', 'correction_reference' => 'CORRECTION-1',
        'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
    ]);
    expect(fn () => $admission->prepare($owner, 'ordinary-three', 'third', []))->toThrow(ValidationException::class);
    expect($owner->fresh()->receivable_revision)->toBe(2);
});

it('requires a fresh complete scan after a real commission maturity changes the user revision', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['currency' => 'CNY']);
    $commission = AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $invoice->user_id,
        'referrer_id' => $owner->id, 'amount' => '2.00', 'status' => 'pending', 'available_at' => now()->subMinute()]);
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->twice()->andReturnUsing(fn (int $id, int $seconds, array $captures): array => $reader->read($id, $seconds, $captures));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    expect($admission->prepare($owner, 'wallet-clearance-request', 'first', [])['revision'])->toBe('0');
    app(MatureAffiliateCommissionsAction::class)->execute($commission->id);
    expect($admission->prepare($owner, 'affiliate-wallet-request', 'second', [])['revision'])->toBe('1')
        ->and($owner->fresh()->affiliate_balance)->toBe('2.00')->and($commission->fresh()->status)->toBe('available');
});
