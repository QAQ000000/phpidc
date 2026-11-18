<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ReconcileMachineOperationAction;
use App\Actions\ReencryptSecretsAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\InstallationState;
use App\Support\MachineOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function reconciliationOperator(string $role = 'upstream-lifecycle'): User
{
    $user = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($user, $role, 'Isolated machine recovery operator');

    return $user;
}

function reconciliationInstance(ProvisionCommand $command = ProvisionCommand::POWER_ON, bool $legacy = false): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    $instance = Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '4253', 'status' => InstanceStatus::ACTIVE,
        'encrypted_credentials' => ['password' => 'PRIVATE-GUEST-SECRET'], 'active_config' => []]);
    $operation = ['request_id' => (string) Str::ulid(), 'command' => $command->value, 'status' => 'unknown'];
    if (! $legacy) {
        $operation += ['requested_at' => now()->subMinutes(6)->toIso8601String(), 'parameters' => [],
            'encrypted_binding' => Crypt::encryptString(json_encode(app(MachineOperation::class)->binding($instance), JSON_THROW_ON_ERROR))];
    }
    $instance->update(['active_config' => ['cloud_operation' => $operation]]);
    DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $instance->user_id, 'subject_type' => Instance::class,
        'subject_id' => $instance->id, 'action' => 'instance.machine.request.'.$operation['request_id'],
        'reason' => 'Original customer operation', 'after' => json_encode(['request_id' => $operation['request_id'], 'status' => 'requested']),
        'created_at' => now()->subMinutes(6), 'updated_at' => now()->subMinutes(6)]);

    return $instance->fresh();
}

function reconciliationHeader(): array
{
    return ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'domainstatus' => 'Active',
        'password' => 'PRIVATE-HEADER-PASSWORD', 'productid' => 17, 'billingcycle' => 'monthly', 'currency_code' => 'CNY'],
        'module_power_status' => true,
        'module_button' => ['control' => [['type' => 'default', 'func' => 'on']]]]];
}

function reconciliationFake(?Closure $override = null): void
{
    $level = DB::transactionLevel();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override !== null && ($result = $override($path, $request)) !== null) {
            return $result;
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-REVIEW-JWT'],
            '/host/header' => reconciliationHeader(),
            '/provision/default' => $request['func'] === 'status' ? ['status' => 200, 'data' => ['status' => 'on']] : ['status' => 200],
            '/host/setdownstream' => ['status' => 200],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['pay_type' => []]]],
            default => throw new RuntimeException('Unexpected reconciliation endpoint '.$path),
        });
    });
}

function reconciliationInput(Instance $instance, User $actor, array $changes = []): array
{
    $review = app(ReconcileMachineOperationAction::class)->inspect($instance, $actor, $instance->active_config['cloud_operation']['request_id'], 'Inspect original task at supplier');

    return $changes + ['resolution_id' => (string) Str::ulid(), 'operation_request_id' => $instance->active_config['cloud_operation']['request_id'],
        'proof' => $review['proof'], 'decision' => 'release', 'reason' => 'Operator independently checked original supplier task', 'confirm' => 1,
        'source' => 'supplier_ticket', 'conclusion' => 'completed', 'evidence_ref' => 'SUPPLIER-TASK-42',
        'evidence' => 'Supplier confirmed this exact original command has ended and there are no queued or running operations.',
        'verified_host_id' => '4253', 'ended_at' => now()->subMinute()->toDateTimeString(), 'idle_confirm' => 1];
}

it('records read-only observations and manual evidence while preserving the unknown result and all billing state', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    $wallet = $instance->user->credit;
    $credentials = $instance->getRawOriginal('encrypted_credentials');
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
    $result = app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    $fresh = $instance->fresh();
    expect($result['released'])->toBeTrue()->and($result['verification'])->toBe('operator_attestation')
        ->and($result['observation']['power_state'])->toBe('on')->and($fresh->active_config['cloud_operation']['status'])->toBe('unknown')
        ->and($fresh->active_config['cloud_operation']['reconciliation']['audit_id'])->toBe($result['audit_id'])
        ->and(app(MachineOperation::class)->blocked($fresh))->toBeFalse()->and($fresh->status)->toBe(InstanceStatus::ACTIVE)
        ->and($fresh->getRawOriginal('encrypted_credentials'))->toBe($credentials)->and($fresh->user->credit)->toBe($wallet);
    foreach (['invoices', 'financial_transactions', 'fulfillment_tasks'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $audit = DB::table('audit_logs')->where('id', $result['audit_id'])->first();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->reason)->toBe($input['reason'])->and($audit->ip)->not->toBeNull()
        ->and($audit->request_id)->not->toBeNull()->and($audit->before.$audit->after)->not->toContain('PRIVATE-', 'encrypted_binding', $input['proof']);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default') && $r['func'] !== 'status');
});

it('restores only new commands and never permits the original command ULID to replay', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    $action = app(ExecuteCustomerPowerCommandAction::class);
    $command = ['request_id' => $input['operation_request_id'], 'reason' => 'Original browser retry', 'confirm' => 1];
    expect(fn () => $action->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON, $command))->toThrow(ValidationException::class);
    $command['request_id'] = (string) Str::ulid();
    expect($action->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON, $command)->success)->toBeTrue();
    expect($instance->fresh()->active_config['cloud_operation']['status'])->toBe('accepted');
    Http::assertSentCount(7);
});

it('returns the committed reconciliation on retries even after expiry or a later new command but rejects changed parameters', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $action = app(ReconcileMachineOperationAction::class);
    $first = $action->execute($instance, $actor, $input);
    $this->travel(10)->minutes();
    expect($action->execute($instance->fresh(), $actor, $input))->toBe($first);
    app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON,
        ['request_id' => (string) Str::ulid(), 'reason' => 'New customer request', 'confirm' => 1]);
    expect($action->execute($instance->fresh(), $actor, $input))->toBe($first);
    expect(fn () => $action->execute($instance, $actor, array_replace($input, ['conclusion' => 'failed'])))->toThrow(ValidationException::class);
    expect(DB::table('audit_logs')->where('action', 'instance.machine.reconcile.'.$input['resolution_id'])->count())->toBe(1);
});

it('keeps unavailable observations and operator hold decisions blocked', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake(fn ($path) => $path === '/host/header' ? Http::response(['status' => 503]) : null);
    $input = reconciliationInput($instance, $actor, ['decision' => 'hold']);
    $result = app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    expect($result['released'])->toBeFalse()->and($result['verification'])->toBe('unresolved')
        ->and($result['observation']['available'])->toBeFalse()->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor,
        array_replace($input, ['resolution_id' => (string) Str::ulid(), 'decision' => 'release'])))->toThrow(ValidationException::class);
});

it('requires task-specific human evidence rather than accepting power alone for destructive or restart commands', function (ProvisionCommand $command): void {
    $instance = reconciliationInstance($command);
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $powerOnly = array_diff_key($input, array_flip(['source', 'evidence_ref', 'evidence', 'idle_confirm']));
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $powerOnly))->toThrow(ValidationException::class);
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
    $result = app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    expect($result['verification'])->toBe('operator_attestation')->and($result['released'])->toBeTrue()
        ->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('unknown');
})->with([ProvisionCommand::REBOOT, ProvisionCommand::HARD_REBOOT, ProvisionCommand::REINSTALL, ProvisionCommand::RESCUE_SYSTEM]);

it('rejects incomplete or contradictory release evidence without any reconciliation audit', function (string $field, mixed $value): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor, [$field => $value]);
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(ValidationException::class);
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'like', 'instance.machine.reconcile.%')->count())->toBe(0);
})->with([
    ['source', null], ['evidence_ref', '   '], ['evidence', str_repeat(' ', 25)], ['evidence', 'power is on'],
    ['verified_host_id', '9999'], ['ended_at', '2000-01-01 00:00:00'], ['ended_at', '2099-01-01 00:00:00'],
    ['idle_confirm', 0], ['reason', ' '], ['confirm', 0], ['proof', 'tampered'],
]);

it('does not release a completed power command whose current power differs from the conclusion', function (ProvisionCommand $command, string $power): void {
    $instance = reconciliationInstance($command);
    $actor = reconciliationOperator();
    reconciliationFake(fn ($path) => $path === '/provision/default' ? Http::response(['status' => 200, 'data' => ['status' => $power]]) : null);
    $input = reconciliationInput($instance, $actor);
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(ValidationException::class);
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
})->with([[ProvisionCommand::POWER_ON, 'off'], [ProvisionCommand::POWER_OFF, 'on'], [ProvisionCommand::HARD_OFF, 'unknown']]);

it('requires independent legacy binding evidence and refuses history that crossed a hot swap', function (): void {
    $instance = reconciliationInstance(legacy: true);
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(ValidationException::class);
    $input['legacy_binding_confirm'] = 1;
    expect(app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input)['released'])->toBeTrue()
        ->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeFalse();
    $other = reconciliationInstance(legacy: true);
    DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id, 'subject_type' => Instance::class,
        'subject_id' => $other->id, 'action' => 'instance.hot_swap', 'reason' => 'Historical rebind', 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(ReconcileMachineOperationAction::class)->inspect($other, $actor,
        $other->active_config['cloud_operation']['request_id'], 'Cannot infer original binding'))->toThrow(ValidationException::class);
});

it('protects a recent or still mutex-owned original request before making any supplier query', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    $action = app(ReconcileMachineOperationAction::class);
    $requestId = $instance->active_config['cloud_operation']['request_id'];
    DB::table('audit_logs')->where('action', 'instance.machine.request.'.$requestId)->update(['created_at' => now()]);
    expect(fn () => $action->inspect($instance, $actor, $requestId, 'Too early'))->toThrow(ValidationException::class);
    DB::table('audit_logs')->where('action', 'instance.machine.request.'.$requestId)->update(['created_at' => now()->subMinutes(6)]);
    $lock = Cache::lock('customer-machine-control:'.$instance->id, 120);
    $lock->get();
    try {
        expect(fn () => $action->inspect($instance, $actor, $requestId, 'Still in progress'))->toThrow(ValidationException::class);
    } finally {
        $lock->release();
    }
    Http::assertNothingSent();
});

it('rejects expired proofs and any changed owner binding endpoint or intent during confirmation', function (string $change): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    if ($change === 'expiry') {
        $this->travel(6)->minutes();
    } elseif ($change === 'endpoint') {
        $instance->upstreamApi->update(['base_url' => 'https://different-supplier.test']);
    } elseif ($change === 'owner') {
        $instance->update(['user_id' => User::factory()->create()->id]);
    } elseif ($change === 'token') {
        $instance->update(['downstream_token' => str_repeat('b', 32)]);
    } else {
        $config = $instance->active_config;
        $config['cloud_operation']['command'] = ProvisionCommand::REBOOT->value;
        $instance->update(['active_config' => $config]);
    }
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance->fresh(), $actor, $input))->toThrow(ValidationException::class);
    expect(DB::table('audit_logs')->where('action', 'like', 'instance.machine.reconcile.%')->count())->toBe(0);
})->with(['expiry', 'endpoint', 'owner', 'token', 'intent']);

it('rechecks binding and operator authority after the confirmation network read', function (string $change): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    reconciliationFake(function ($path) use ($instance, $actor, $change) {
        if ($path === '/host/header') {
            if ($change === 'binding') {
                $instance->fresh()->update(['upstream_instance_id' => '9999']);
            } else {
                app(AssignAdminRoleAction::class)->execute($actor, 'upstream-lifecycle', 'Revoked during query', true);
            }
        }

        return null;
    });
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))
        ->toThrow($change === 'binding' ? ValidationException::class : AuthorizationException::class);
    expect(DB::table('audit_logs')->where('action', 'like', 'instance.machine.reconcile.%')->count())->toBe(0);
})->with(['binding', 'permission']);

it('refuses release while fulfillment work or cancellation still needs reconciliation', function (string $block): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    if ($block === 'cancellation') {
        $config = $instance->active_config;
        $config['cancellation'] = ['requested' => true];
        $instance->update(['active_config' => $config]);
    } else {
        FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'status' => FulfillmentTaskStatus::from($block)]);
    }
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance->fresh(), $actor, $input))->toThrow(ValidationException::class);
})->with(['pending', 'running', 'failed', 'cancellation']);

it('provides a read-only admin history and a separate no-store confirmation without credential leakage', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    $viewer = reconciliationOperator('upstream-viewer');
    $path = '/admin/machine-operation-review?instance_id='.$instance->id;
    $this->actingAs($instance->user)->get($path)->assertForbidden();
    $this->actingAs($viewer)->get($path)->assertOk()->assertSee('Original customer operation')
        ->assertDontSee('查询上游现状并核对')->assertDontSee('PRIVATE-GUEST-SECRET')->assertDontSee('encrypted_binding');
    $request = ['operation_request_id' => $instance->active_config['cloud_operation']['request_id'], 'reason' => 'Supplier investigation'];
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine-review', $request)->assertForbidden();
    Http::assertNothingSent();
    reconciliationFake();
    $this->actingAs($actor)->get($path)->assertOk()->assertSee('查询上游现状并核对');
    Http::assertNothingSent();
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine-review', $request)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertSee('当前电源：on')->assertSee('保持未决')->assertSee('上游任务或工单编号')
        ->assertDontSee('PRIVATE-HEADER-PASSWORD')->assertDontSee('PRIVATE-REVIEW-JWT')->assertDontSee('PRIVATE-GUEST-SECRET');
    $input = reconciliationInput($instance, $actor);
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine-reconcile', $input)
        ->assertRedirect($path)->assertSessionHas('inventory_status');
    $this->get($path)->assertOk()->assertSee('SUPPLIER-TASK-42')->assertSee('人工核对后允许新请求');
});

it('does not flash the encrypted proof after validation errors or allow a viewer or customer to reconcile', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $path = '/admin/upstream-instances/'.$instance->id.'/machine-reconcile';
    $this->actingAs($actor)->post($path, array_replace($input, ['reason' => '']))->assertSessionHasErrors('reason');
    expect(session()->getOldInput('proof'))->toBeNull();
    $this->actingAs(reconciliationOperator('upstream-viewer'))->post($path, $input)->assertForbidden();
    $this->actingAs($instance->user)->post($path, $input)->assertForbidden();
    expect(DB::table('audit_logs')->where('action', 'like', 'instance.machine.reconcile.%')->count())->toBe(0);
});

it('rolls back the release pointer when its audit cannot be committed', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'audit_logs')
            && collect($query->bindings)->contains(fn ($value) => is_string($value) && str_starts_with($value, 'instance.machine.reconcile.'))) {
            throw new RuntimeException('machine audit unavailable');
        }
    });
    try {
        expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(RuntimeException::class, 'machine audit unavailable');
    } finally {
        $fail = false;
    }
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue()
        ->and($instance->fresh()->active_config)->toBe($instance->active_config);
});

it('rejects a fabricated release pointer that has no matching durable audit evidence', function (): void {
    $instance = reconciliationInstance();
    $config = $instance->active_config;
    $config['cloud_operation']['reconciliation'] = ['audit_id' => 999999];
    $instance->update(['active_config' => $config]);
    expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeTrue();
});

it('archives old-binding intent during hot swap and refuses to apply a previously issued review proof to the new host', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    app(AssignAdminRoleAction::class)->execute($actor, 'upstream-migrator', 'Allow isolated hot swap');
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $target = UpstreamApi::factory()->create();
    reconciliationFake(fn ($path) => $path === '/host/header'
        ? Http::response(array_replace_recursive(reconciliationHeader(), ['data' => ['host_data' => ['id' => 199]]])) : null);
    app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'keep_orphan', 'Independent replacement host', $actor, true);
    $fresh = $instance->fresh();
    expect($fresh->active_config)->not->toHaveKey('cloud_operation')->and(app(MachineOperation::class)->blocked($fresh))->toBeFalse();
    $row = DB::table('audit_logs')->where('action', 'instance.machine.archived')->first();
    expect(json_decode($row->before, true)['binding']['upstream_instance_id'])->toBe('4253')
        ->and(json_decode($row->after, true)['requires_supplier_review'])->toBeTrue()
        ->and($row->before.$row->after)->not->toContain('PRIVATE-', 'encrypted_binding');
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($fresh, $actor, $input))->toThrow(ValidationException::class);
});

it('keeps a timed out original command unknown and records only non-secret intent evidence', function (): void {
    $instance = reconciliationInstance();
    $instance->update(['active_config' => []]);
    reconciliationFake(fn ($path, $request) => $path === '/provision/default' && $request['func'] === 'on'
        ? throw new ConnectionException('Timeout after command submission') : null);
    $requestId = (string) Str::ulid();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON,
        ['request_id' => $requestId, 'reason' => 'Submit command once', 'confirm' => 1, 'temp_pass' => 'PRIVATE-PASSWORD-INPUT']);
    $operation = $instance->fresh()->active_config['cloud_operation'];
    expect($result->retryable)->toBeTrue()->and($operation['status'])->toBe('unknown')
        ->and(json_encode($operation))->not->toContain('PRIVATE-PASSWORD-INPUT')->and($operation)->toHaveKey('requested_at')
        ->and(json_decode(Crypt::decryptString($operation['encrypted_binding']), true))->toBe(app(MachineOperation::class)->binding($instance->fresh()));
});

it('does not let a late response overwrite a reconciliation that committed while the original request was away', function (): void {
    $instance = reconciliationInstance();
    $instance->update(['active_config' => []]);
    $actor = reconciliationOperator();
    $releasedAudit = null;
    reconciliationFake(function ($path, $request) use ($instance, $actor, &$releasedAudit) {
        if ($path === '/provision/default' && $request['func'] === 'on') {
            $this->travel(6)->minutes();
            $fresh = $instance->fresh();
            Cache::lock('customer-machine-control:'.$instance->id)->forceRelease();
            $input = reconciliationInput($fresh, $actor, ['conclusion' => 'not_executed']);
            $releasedAudit = app(ReconcileMachineOperationAction::class)->execute($fresh, $actor, $input)['audit_id'];
        }

        return null;
    });
    $input = ['request_id' => (string) Str::ulid(), 'reason' => 'Slow original request', 'confirm' => 1];
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON, $input))->toThrow(ValidationException::class);
    expect($instance->fresh()->active_config['cloud_operation']['status'])->toBe('requested')
        ->and($instance->fresh()->active_config['cloud_operation']['reconciliation']['audit_id'])->toBe($releasedAudit);
    $result = json_decode(DB::table('audit_logs')->where('action', 'instance.machine.result.'.$input['request_id'])->value('after'), true);
    expect($result['binding_matches'])->toBeFalse()->and($result['status'])->toBe('accepted');
});

it('rotates encrypted operation binding without changing its identity or reopening the original request', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    $result = app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));
    $state = app(InstallationState::class);
    $state->write('installed.lock', $state->seal(['format' => 1, 'admin_id' => $actor->id], $oldKey));
    File::ensureDirectoryExists(storage_path('framework'));
    File::put(storage_path('framework/down'), '{}');
    $binding = app(MachineOperation::class)->binding($instance);
    app(ReencryptSecretsAction::class)->execute($oldKey, $newKey, 'Rotate machine recovery binding', true);
    $new = $state->encrypter($newKey);
    expect(json_decode($new->decryptString($instance->fresh()->active_config['cloud_operation']['encrypted_binding']), true))->toBe($binding);
    $oldEncrypter = app('encrypter');
    try {
        app()->instance('encrypter', $new);
        Crypt::clearResolvedInstance('encrypter');
        expect(app(MachineOperation::class)->blocked($instance->fresh()))->toBeFalse()
            ->and(app(ReconcileMachineOperationAction::class)->execute($instance->fresh(), $actor, $input))->toBe($result);
    } finally {
        app()->instance('encrypter', $oldEncrypter);
        Crypt::clearResolvedInstance('encrypter');
    }
});

it('requires CSRF on both investigation and reconciliation posts', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    $this->actingAs($actor);
    $this->app->detectEnvironment(fn () => 'local');
    $this->withSession(['_token' => 'machine-review-csrf']);
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine-review', [])->assertStatus(419);
    $this->post('/admin/upstream-instances/'.$instance->id.'/machine-reconcile', [])->assertStatus(419);
    Http::assertNothingSent();
});

it('recovers a durable requested intent after a process interruption without changing its original status', function (): void {
    $instance = reconciliationInstance();
    $config = $instance->active_config;
    $config['cloud_operation']['status'] = 'requested';
    $instance->update(['active_config' => $config]);
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance->fresh(), $actor, ['conclusion' => 'not_executed']);
    expect(app(ReconcileMachineOperationAction::class)->execute($instance->fresh(), $actor, $input)['released'])->toBeTrue()
        ->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('requested')
        ->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeFalse();
});

it('keeps a mismatched authenticated host snapshot unavailable instead of releasing its pending operation', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake(fn ($path) => $path === '/host/header'
        ? Http::response(array_replace_recursive(reconciliationHeader(), ['data' => ['host_data' => ['id' => 9999]]])) : null);
    $input = reconciliationInput($instance, $actor);
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/provision/default'));
});

it('denies a replay through a stale operator model after authority is revoked', function (): void {
    $instance = reconciliationInstance();
    $actor = reconciliationOperator();
    reconciliationFake();
    $input = reconciliationInput($instance, $actor);
    app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input);
    expect($actor->can('upstream.lifecycle'))->toBeTrue();
    app(AssignAdminRoleAction::class)->execute(User::findOrFail($actor->id), 'upstream-lifecycle', 'Revoke stale-session authority', true);
    expect(fn () => app(ReconcileMachineOperationAction::class)->execute($instance, $actor, $input))->toThrow(AuthorizationException::class);
});

it('prevents an original request paused at the header read from posting after manual release', function (): void {
    $instance = reconciliationInstance();
    $instance->update(['active_config' => []]);
    $actor = reconciliationOperator();
    $released = false;
    reconciliationFake(function ($path) use ($instance, $actor, &$released) {
        if ($path === '/host/header' && ! $released) {
            $released = true;
            $this->travel(6)->minutes();
            Cache::lock('customer-machine-control:'.$instance->id)->forceRelease();
            $fresh = $instance->fresh();
            $input = reconciliationInput($fresh, $actor, ['conclusion' => 'not_executed']);
            app(ReconcileMachineOperationAction::class)->execute($fresh, $actor, $input);
        }

        return null;
    });
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), ProvisionCommand::POWER_ON,
        ['request_id' => (string) Str::ulid(), 'reason' => 'Original delayed header', 'confirm' => 1]))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/provision/default') && $request['func'] !== 'status');
    expect($instance->fresh()->active_config['cloud_operation']['status'])->toBe('requested')
        ->and(app(MachineOperation::class)->blocked($instance->fresh()))->toBeFalse();
});
