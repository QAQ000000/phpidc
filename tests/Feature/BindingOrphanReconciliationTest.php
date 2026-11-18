<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\BackfillBindingOrphanCasesAction;
use App\Actions\ReadReconciliationEvidenceAction;
use App\Actions\ReconcileBindingOrphanAction;
use App\Actions\RecordBindingOrphanCaseAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use App\Services\BindingReconciliationCases;
use App\Services\NotificationManager;
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

function bindingOrphanFixture(bool $snapshot = true, bool $record = true): array
{
    $owner = User::factory()->create()->fresh();
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'upstream-orphan-reviewer', 'Isolated orphan acceptance');
    $account = UpstreamApi::factory()->create();
    $instance = Instance::factory()->create(['user_id' => $owner->id, 'status' => InstanceStatus::DELETED,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => UpstreamApi::factory()->create()->id, 'upstream_instance_id' => '999']);
    $binding = InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '101', 'bound_at' => now()->subMonths(2),
        'unbound_at' => now()->subMonth(), 'unbind_reason' => 'Original switch retained this resource', 'upstream_disposition' => 'kept_orphan',
        'source_snapshot' => $snapshot ? ['upstream_api_id' => $account->id, 'upstream_instance_id' => '101',
            'protocol' => 'classic_zjmf', 'base_url' => $account->base_url] : null]);
    $case = $record ? DB::transaction(fn () => app(RecordBindingOrphanCaseAction::class)->execute($binding)) : null;
    $state = (object) ['status' => 'Deleted', 'host' => '101', 'hook' => null, 'calls' => []];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[] = [$request->method(), $path];
        if ($state->hook !== null) {
            ($state->hook)($path);
        }
        if ($path === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-ORPHAN-JWT']);
        }
        expect($path)->toBe('/host/header')->and($request['host_id'])->toBe('101')->and($request->method())->toBe('GET');

        return Http::response(['status' => 200, 'data' => ['host_data' => ['id' => $state->host, 'domainstatus' => $state->status,
            'password' => 'PRIVATE-OLD-HOST-SECRET']]]);
    });

    return [$owner, $actor, $binding, $case, $account, $instance, $state];
}

function bindingOrphanInput(ReconciliationCase $case, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'expected_revision' => $case->revision,
        'operation' => 'terminate_verified', 'reason' => 'Authenticated original resource reconciliation',
        'evidence' => 'PRIVATE-ORIGINAL-DISPOSITION-PROOF', 'confirm' => '1'], $changes);
}

function bindingOrphanTransfer(ReconciliationCase $case): array
{
    return bindingOrphanInput($case, ['operation' => 'transfer_verified', 'recipient' => 'PRIVATE-VERIFIED-RECIPIENT',
        'target_resource' => 'Original account / host 101', 'acceptance_reference' => 'PRIVATE-ACCEPTANCE-001',
        'accepted_at' => now()->subHour()->toIso8601String(), 'responsibility_accepted' => '1']);
}

it('queries the original endpoint and host and releases only its verified orphan closure blocker', function (): void {
    [$owner, $actor, $binding, $case, , $instance, $state] = bindingOrphanFixture();
    expect(app(AccountClosureEligibility::class)->forUser($owner)['reasons'])->toHaveKey('orphans');
    $old = $binding->fresh()->getRawOriginal();
    $current = $instance->fresh()->getRawOriginal();
    $input = bindingOrphanInput($case);
    $updated = app(ReconcileBindingOrphanAction::class)->execute($case, $actor, $input);
    expect($updated->status)->toBe('resolved')->and($updated->revision)->toBe(2)
        ->and(app(BindingReconciliationCases::class)->isResolved($updated))->toBeTrue()
        ->and(app(AccountClosureEligibility::class)->forUser($owner)['eligible'])->toBeTrue()
        ->and($binding->fresh()->getRawOriginal())->toBe($old)->and($instance->fresh()->getRawOriginal())->toBe($current)
        ->and(DB::table('notification_events')->where('event_type', 'reconciliation.updated')->count())->toBe(1);
    $counts = [count($state->calls), ReconciliationEntry::count(), DB::table('audit_logs')->count()];
    app(ReconcileBindingOrphanAction::class)->execute($case, $actor, $input);
    expect([count($state->calls), ReconciliationEntry::count(), DB::table('audit_logs')->count()])->toBe($counts);
    $raw = DB::table('reconciliation_entries')->where('action', 'resolved')->value('evidence');
    expect($raw)->not->toContain('PRIVATE-', 'classic.test');
    expect(ReconciliationEntry::where('action', 'resolved')->sole()->evidence['base_url'])->toBe('https://classic.test');
});

it('does not equate accepted active suspended or another host with the original Deleted resource', function (string $status, string $host): void {
    [, $actor, , $case, , , $state] = bindingOrphanFixture();
    $state->status = $status;
    $state->host = $host;
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanInput($case)))
        ->toThrow(ValidationException::class);
    expect($case->fresh()->status)->toBe('open')->and(ReconciliationEntry::count())->toBe(1);
})->with([['Active', '101'], ['Suspended', '101'], ['Accepted', '101'], ['Deleted', '102']]);

it('requires historical endpoint evidence and never fabricates an old snapshot', function (): void {
    [, $actor, $binding, $case] = bindingOrphanFixture(false);
    $action = app(ReconcileBindingOrphanAction::class);
    expect(fn () => $action->execute($case, $actor, bindingOrphanInput($case)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    $updated = $action->execute($case, $actor, bindingOrphanInput($case, ['original_base_url' => 'https://classic.test/',
        'historical_endpoint_evidence' => 'PRIVATE-ORIGINAL-CONTRACT-ENDPOINT']));
    expect(app(BindingReconciliationCases::class)->isResolved($updated))->toBeTrue()
        ->and($binding->fresh()->source_snapshot)->toBeNull();
});

it('refuses to send current credentials to changed or hand supplied endpoints', function (bool $historical): void {
    [, $actor, , $case, $account] = bindingOrphanFixture(! $historical);
    $account->update(['base_url' => 'https://new-account-endpoint.test']);
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanInput($case,
        ['original_base_url' => 'https://classic.test', 'historical_endpoint_evidence' => 'Original archived endpoint'])))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([false, true]);

it('rechecks account credentials after the unlocked authenticated query', function (): void {
    [, $actor, , $case, $account, , $state] = bindingOrphanFixture();
    $state->hook = function (string $path) use ($account): void {
        if ($path === '/host/header') {
            $account->update(['encrypted_secret' => 'Different credentials during original read']);
        }
    };
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanInput($case)))->toThrow(ValidationException::class);
    expect($case->fresh()->revision)->toBe(1)->and(ReconciliationEntry::count())->toBe(1);
});

it('records a completed transfer with explicit responsibility acceptance without supplier mutations', function (): void {
    [$owner, $actor, $binding, $case] = bindingOrphanFixture(false);
    $updated = app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanTransfer($case));
    expect(app(BindingReconciliationCases::class)->isResolved($updated))->toBeTrue()
        ->and(app(BindingReconciliationCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse()
        ->and($binding->fresh()->upstream_disposition)->toBe('kept_orphan');
    Http::assertNothingSent();
});

it('requires complete actual transfer evidence', function (string $field): void {
    [, $actor, , $case] = bindingOrphanFixture();
    $input = bindingOrphanTransfer($case);
    unset($input[$field]);
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, $input))->toThrow(ValidationException::class);
    expect(ReconciliationEntry::count())->toBe(1);
    Http::assertNothingSent();
})->with(['recipient', 'target_resource', 'acceptance_reference', 'accepted_at', 'responsibility_accepted', 'evidence', 'reason', 'confirm']);

it('rejects stale revisions and changed intent under the same request key', function (): void {
    [, $actor, , $case] = bindingOrphanFixture();
    $input = bindingOrphanTransfer($case);
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, [...$input, 'expected_revision' => 2]))->toThrow(ValidationException::class);
    app(ReconcileBindingOrphanAction::class)->execute($case, $actor, $input);
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, [...$input, 'recipient' => 'Changed recipient']))->toThrow(ValidationException::class);
    expect(ReconciliationEntry::count())->toBe(2);
});

it('does not allow a view role or the customer to reconcile or inspect encrypted evidence', function (): void {
    [$owner, , , $case] = bindingOrphanFixture();
    $viewer = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($viewer, 'upstream-viewer', 'Read-only isolated acceptance');
    foreach ([$owner, $viewer] as $candidate) {
        expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $candidate, bindingOrphanTransfer($case)))->toThrow(AuthorizationException::class);
        expect(fn () => app(ReadReconciliationEvidenceAction::class)->execute($case, $candidate, 'Inspect original proof'))->toThrow(AuthorizationException::class);
    }
    expect(ReconciliationEntry::count())->toBe(1);
    Http::assertNothingSent();
});

it('atomically rolls back the conclusion audit and append when outbox persistence fails', function (): void {
    [, $actor, , $case] = bindingOrphanFixture();
    $notifications = Mockery::mock(NotificationManager::class);
    $notifications->shouldReceive('queueOperations')->once()->andThrow(new RuntimeException('Outbox unavailable'));
    app()->instance(NotificationManager::class, $notifications);
    $audits = DB::table('audit_logs')->count();
    expect(fn () => app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanTransfer($case)))->toThrow(RuntimeException::class, 'Outbox unavailable');
    expect($case->fresh()->status)->toBe('open')->and($case->fresh()->revision)->toBe(1)
        ->and(ReconciliationEntry::count())->toBe(1)->and(DB::table('audit_logs')->count())->toBe($audits);
});

it('reopens only a disproven conclusion and permits a new evidence backed resolution', function (): void {
    [$owner, $actor, , $case] = bindingOrphanFixture();
    $action = app(ReconcileBindingOrphanAction::class);
    $resolved = $action->execute($case, $actor, bindingOrphanTransfer($case));
    expect(fn () => $action->execute($case, $actor, bindingOrphanInput($resolved, ['operation' => 'reopen'])))->toThrow(ValidationException::class);
    DB::table('reconciliation_entries')->where('case_id', $case->id)->where('action', 'resolved')->update(['evidence' => 'corrupt-out-of-band-evidence']);
    expect(app(BindingReconciliationCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    $reopened = $action->execute($case, $actor, bindingOrphanInput($resolved, ['operation' => 'reopen']));
    expect($reopened->status)->toBe('open')->and($reopened->revision)->toBe(3);
    $again = $action->execute($case, $actor, bindingOrphanTransfer($reopened));
    expect($again->revision)->toBe(4)->and(app(BindingReconciliationCases::class)->isResolved($again))->toBeTrue();
});

it('keeps unbackfilled original orphans blocking and backfills idempotently', function (): void {
    [$owner, , $binding] = bindingOrphanFixture(false, false);
    expect(ReconciliationCase::count())->toBe(0)->and(app(BindingReconciliationCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    app(BackfillBindingOrphanCasesAction::class)->execute();
    $original = ReconciliationCase::sole()->getRawOriginal();
    app(BackfillBindingOrphanCasesAction::class)->execute();
    expect(ReconciliationCase::count())->toBe(1)->and(ReconciliationEntry::count())->toBe(1)
        ->and(ReconciliationCase::sole()->getRawOriginal())->toBe($original)->and($binding->fresh()->source_snapshot)->toBeNull();
    Http::assertNothingSent();
});

it('serves the registered reconciliation page and audited private evidence through actual routes', function (): void {
    [, $actor, , $case] = bindingOrphanFixture();
    app(ReconcileBindingOrphanAction::class)->execute($case, $actor, bindingOrphanTransfer($case));
    $this->actingAs($actor)->get('/admin/reconciliations?case='.$case->id)->assertOk()->assertDontSee('PRIVATE-VERIFIED-RECIPIENT');
    $this->post('/admin/reconciliations/'.$case->id.'/evidence', ['reason' => 'Read source responsibility evidence', 'confirm' => '1'])
        ->assertOk()->assertSee('PRIVATE-VERIFIED-RECIPIENT')->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    $this->assertDatabaseHas('audit_logs', ['action' => 'reconciliation.evidence_view', 'subject_id' => $case->id]);
});
