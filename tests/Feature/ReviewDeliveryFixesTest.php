<?php

declare(strict_types=1);

use App\Actions\AssignUpstreamRoleAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ReconcileHostSyncAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestInstanceOperationAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfHour());
});

function reviewDeliveryFixture(): array
{
    $account = UpstreamApi::factory()->create();
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_pid' => 17]);
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '99',
        'encrypted_credentials' => ['password' => 'original-password'], 'last_synced_at' => null]);
    $state = (object) ['upgraded' => false, 'hook' => null, 'calls' => [], 'domainstatus' => 'Active', 'target' => []];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($instance, $state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[$path] = ($state->calls[$path] ?? 0) + 1;
        if ($state->hook !== null && ($response = ($state->hook)($path, $request)) !== null) {
            return $response;
        }
        $body = match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-review-token'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => (string) $request['host_id'] === '199'
                ? array_replace(['id' => 199, 'domainstatus' => 'Active', 'productid' => 17, 'billingcycle' => 'monthly', 'currency_code' => 'CNY',
                    'password' => 'target-password', 'nextduedate' => $instance->next_due_at->getTimestamp()], $state->target)
                : ['id' => 99, 'domainstatus' => $state->domainstatus, 'nextduedate' => $instance->next_due_at->getTimestamp(), 'password' => 'upstream-password']]],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['id' => 17, 'pay_type' => ['pay_monthly_cycle' => 1]]]],
            '/host/cancel', '/host/setdownstream' => ['status' => 200],
            '/upgrade/upgrade_config_post' => ['status' => 200],
            '/upgrade/upgrade_config_page' => ['status' => 200, 'data' => ['total' => '8.00', 'currency' => ['code' => 'CNY']]],
            '/upgrade/checkout_config_upgrade' => ['status' => 200, 'data' => ['invoiceid' => 765]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => '8.00', 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001],
            '/upgrade/index/99' => ['status' => 200, 'data' => ['host' => [['oid' => 50, 'subid' => $state->upgraded ? 502 : 501, 'option_type' => 1]]]],
            default => throw new LogicException('Unexpected isolated review endpoint: '.$path),
        };
        if ($path === '/apply_credit') {
            $state->upgraded = true;
        }
        if ($path === '/host/cancel') {
            $state->domainstatus = 'Deleted';
        }

        return Http::response($body);
    });

    return [$user, $product, $instance, $account, $state];
}

function reviewUpgradeTask(Instance $instance): FulfillmentTask
{
    $context = ['upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => $instance->upstream_instance_id,
        'base_url' => $instance->upstreamApi->base_url, 'token_hash' => hash('sha256', $instance->downstream_token ?? ''),
        'recurring_after' => '12.00', 'options' => ['configoption' => [50 => 502], 'pairs' => []], 'reason' => 'Customer-approved upgrade'];
    $invoice = Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'product_id' => $instance->product_id,
        'instance_id' => $instance->id, 'type' => 'upgrade', 'upstream_expected_cost' => '8.00']);

    return FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $invoice->id,
        'action' => 'upgrade', 'payload' => ['encrypted_operation' => Crypt::encryptString(json_encode($context, JSON_THROW_ON_ERROR))]]);
}

it('retries a paid upgrade after a competing quote lock without retaining a false uncertain creation', function (): void {
    [$user, , $instance, $account, $state] = reviewDeliveryFixture();
    $task = reviewUpgradeTask($instance);
    $lock = Cache::lock('zjmf:config:'.$account->id.':99', 210);
    expect($lock->get())->toBeTrue();
    try {
        app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
        expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)
            ->and($task->fresh()->attempts)->toBe(1)
            ->and($task->fresh()->payload)->not->toHaveKey('operation')
            ->and($task->fresh()->available_at->isFuture())->toBeTrue();
        Http::assertNothingSent();
        Queue::assertPushed(ExecuteFulfillmentTaskJob::class, fn ($job) => $job->taskId === $task->id);
    } finally {
        $lock->release();
    }
    $this->travel(61)->seconds();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->fresh()->attempts)->toBe(2)
        ->and($state->calls['/upgrade/checkout_config_upgrade'])->toBe(1)
        ->and($state->calls['/apply_credit'])->toBe(1)
        ->and($instance->fresh()->recurring_amount)->toBe('12.00')
        ->and($instance->fresh()->active_config['upstream_configoption'][50])->toBe(502)
        ->and($task->invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($user->fresh()->credit)->toBe('100.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

function reviewTerminatedBinding(): array
{
    [$user, $product, $instance, $account, $state] = reviewDeliveryFixture();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'product_id' => $product->id, 'instance_id' => $instance->id]);
    $actor = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($actor, 'upstream-migrator', 'Authorize reviewed resource replacement');
    $target = UpstreamApi::factory()->create();
    $termination = app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'Customer requests termination', (string) Str::ulid(), $user, 'client_action');
    app(ExecuteFulfillmentTaskAction::class)->execute($termination->id);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)
        ->and($termination->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);

    return [$user, $instance->fresh(), $account, $state, $invoice, $actor, $target];
}

it('rebinds a normally terminated Deleted service only through the confirmed administrator terminate flow', function (string $targetStatus): void {
    [$user, $instance, $account, $state, $invoice, $actor, $target] = reviewTerminatedBinding();
    $state->target = ['domainstatus' => $targetStatus];
    $token = $instance->downstream_token;
    app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Replace a confirmed deleted resource', $actor, true);
    $fresh = $instance->fresh();
    expect($fresh->status)->toBe(InstanceStatus::from($targetStatus))
        ->and($fresh->upstream_api_id)->toBe($target->id)
        ->and($fresh->upstream_instance_id)->toBe('199')
        ->and($fresh->downstream_token)->not->toBe($token)
        ->and($fresh->encrypted_credentials['password'])->toBe('target-password')
        ->and($fresh->active_config['mapping_requires_review'])->toBeTrue()
        ->and($fresh->active_config)->not->toHaveKey('cancellation')
        ->and($user->fresh()->credit)->toBe('100.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and(InstanceStatus::DELETED->canTransitionTo(InstanceStatus::ACTIVE))->toBeFalse();
    $this->assertDatabaseHas('instance_upstream_bindings', ['instance_id' => $instance->id, 'upstream_api_id' => $account->id,
        'upstream_instance_id' => '99', 'upstream_disposition' => 'terminated']);
    $this->assertDatabaseHas('instance_upstream_bindings', ['instance_id' => $instance->id, 'upstream_api_id' => $target->id,
        'upstream_instance_id' => '199', 'unbound_at' => null]);
    $audit = DB::table('audit_logs')->where('action', 'instance.hot_swap')->where('subject_id', $instance->id)->sole();
    expect(json_decode($audit->before, true)['status'])->toBe('Deleted')
        ->and(json_decode($audit->after, true)['status'])->toBe($targetStatus)
        ->and(json_decode($audit->after, true)['terminal_rebinding'])->toBeTrue()
        ->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->reason)->toBe('Replace a confirmed deleted resource');
    $count = Http::recorded()->count();
    app(ReconcileHostSyncAction::class)->execute($instance->id, $account->id, '99', $token);
    expect(Http::recorded()->count())->toBe($count)->and($instance->fresh()->status)->toBe(InstanceStatus::from($targetStatus));
    $callback = FulfillmentTask::where('action', 'register_callback')->sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($callback->id);
    expect($callback->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($state->calls['/host/setdownstream'])->toBe(1)
        ->and($state->calls['/apply_credit'] ?? 0)->toBe(0);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['Active', 'Suspended']);

it('does not extend terminal rebinding to other states or keep_orphan', function (string $status, string $disposition): void {
    [, $instance, , , , $actor, $target] = reviewTerminatedBinding();
    $instance->update(['status' => InstanceStatus::from($status)]);
    $count = Http::recorded()->count();
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', $disposition, 'Invalid terminal replacement', $actor, true))
        ->toThrow(DomainException::class);
    expect($instance->fresh()->status)->toBe(InstanceStatus::from($status))->and(Http::recorded()->count())->toBe($count);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with([['Deleted', 'keep_orphan'], ['Cancelled', 'terminate'], ['Fraud', 'terminate'], ['ProvisionFailed', 'terminate'], ['Pending', 'terminate']]);

it('requires administrator authority and explicit confirmation for a Deleted replacement', function (): void {
    [$user, $instance, , , , $actor, $target] = reviewTerminatedBinding();
    $action = app(HotSwapInstanceDriverAction::class);
    $count = Http::recorded()->count();
    expect(fn () => $action->execute($instance, $target->id, '199', '17', 'terminate', 'Customer cannot replace it', $user, true))->toThrow(AuthorizationException::class);
    expect(fn () => $action->execute($instance, $target->id, '199', '17', 'terminate', 'Unconfirmed replacement', $actor, false))->toThrow(DomainException::class);
    expect(Http::recorded()->count())->toBe($count)->and($instance->fresh()->status)->toBe(InstanceStatus::DELETED);
});

it('requires a new authenticated Deleted observation for the old resource', function (): void {
    [, $instance, , $state, , $actor, $target] = reviewTerminatedBinding();
    $state->domainstatus = 'Active';
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Old deletion was not confirmed', $actor, true))
        ->toThrow(DomainException::class, 'First terminate the old resource');
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
});

it('rejects mismatched replacement resource properties', function (array $change): void {
    [, $instance, , $state, , $actor, $target] = reviewTerminatedBinding();
    $state->target = $change;
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Target properties do not match', $actor, true))
        ->toThrow(DomainException::class);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with([[['productid' => 18]], [['billingcycle' => 'annually']], [['currency_code' => 'USD']], [['domainstatus' => 'Deleted']], [['id' => 200]]]);

it('rejects refunded invoices and inconsistent durable refund evidence before reactivating a Deleted service', function (string $evidence): void {
    [$user, $instance, , , $invoice, $actor, $target] = reviewTerminatedBinding();
    if ($evidence === 'status') {
        $invoice->update(['status' => InvoiceStatus::REFUNDED]);
    } elseif ($evidence === 'ledger') {
        DB::table('financial_transactions')->insert(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => 'credit_in',
            'amount' => '10.00', 'reference_key' => 'refund:'.$invoice->id, 'created_at' => now(), 'updated_at' => now()]);
    } elseif ($evidence === 'audit') {
        DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id, 'action' => 'invoice.refund',
            'subject_type' => Invoice::class, 'subject_id' => $invoice->id, 'reason' => 'Historical refund evidence', 'created_at' => now(), 'updated_at' => now()]);
    } else {
        Gate::define('refund-invoice', fn (User $operator, Invoice $subject) => $operator->is($actor) && $subject->is($invoice));
        app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Refund terminated resource', actor: $actor);
    }
    $credit = $user->fresh()->credit;
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'A refund must prevent resurrection', $actor, true))
        ->toThrow(DomainException::class, 'Refunded services');
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($user->fresh()->credit)->toBe($credit);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
    $this->assertDatabaseMissing('fulfillment_tasks', ['instance_id' => $instance->id, 'action' => 'register_callback']);
})->with(['status', 'ledger', 'audit', 'actual_refund']);

it('does not hide unresolved financial or lifecycle tasks behind a terminal replacement', function (string $status, bool $financial): void {
    [, $instance, , , $invoice, $actor, $target] = reviewTerminatedBinding();
    FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'invoice_id' => $financial ? $invoice->id : null,
        'action' => $financial ? 'renew' : 'suspend', 'status' => $status]);
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Unresolved task cannot be bypassed', $actor, true))
        ->toThrow(DomainException::class, 'financial operations need reconciliation');
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with(['pending', 'running', 'failed'])->with([true, false]);

it('rejects an unpaid invoice before terminal replacement', function (): void {
    [, $instance, , , , $actor, $target] = reviewTerminatedBinding();
    Invoice::factory()->create(['instance_id' => $instance->id, 'user_id' => $instance->user_id, 'product_id' => $instance->product_id, 'type' => 'renew']);
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Unpaid invoice must be resolved', $actor, true))->toThrow(DomainException::class);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
});

it('rejects terminal replacement if either supplier account changes during verification', function (string $change, string $which): void {
    [, $instance, $account, $state, , $actor, $target] = reviewTerminatedBinding();
    $changed = $which === 'old' ? $account : $target;
    $state->hook = function ($path, $request) use ($changed, $change) {
        if ($path === '/host/header' && (string) $request['host_id'] === '199') {
            $changed->update(match ($change) {
                'endpoint' => ['base_url' => 'https://changed.example.test'],
                'disabled' => ['is_active' => false],
                'secret' => ['encrypted_secret' => 'changed-api-secret'],
                'auth_mode' => ['auth_header_mode' => 'raw_jwt'],
                'username' => ['username' => 'changed@example.test'],
                'config' => ['config' => ['allow_credit_limit' => true]],
            });
        }

        return null;
    };
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Account changed during verification', $actor, true))
        ->toThrow(DomainException::class);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($instance->fresh()->upstream_api_id)->toBe($account->id);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with(['endpoint', 'disabled', 'secret', 'auth_mode', 'username', 'config'])->with(['old', 'target']);

it('rechecks refunds committed while terminal replacement was waiting for HTTP', function (): void {
    [$user, $instance, , $state, $invoice, $actor, $target] = reviewTerminatedBinding();
    Gate::define('refund-invoice', fn (User $operator, Invoice $subject) => $operator->is($actor) && $subject->is($invoice));
    $state->hook = function ($path, $request) use ($invoice, $actor) {
        if ($path === '/host/header' && (string) $request['host_id'] === '199') {
            app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Refund while replacement verification is in flight', actor: $actor);
        }

        return null;
    };
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Refund won the race', $actor, true))->toThrow(DomainException::class, 'Refunded services');
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)
        ->and($user->fresh()->credit)->toBe('110.00');
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
});

it('rejects a binding or administrator permission change during terminal verification', function (string $change): void {
    [, $instance, $account, $state, , $actor, $target] = reviewTerminatedBinding();
    $state->hook = function ($path, $request) use ($instance, $actor, $change) {
        if ($path === '/host/header' && (string) $request['host_id'] === '199') {
            if ($change === 'binding') {
                $instance->update(['downstream_token' => str_repeat('b', 32)]);
            } else {
                $actor->syncRoles([]);
            }
        }

        return null;
    };
    $exception = $change === 'binding' ? DomainException::class : AuthorizationException::class;
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Authority or binding changed', $actor, true))->toThrow($exception);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($instance->fresh()->upstream_api_id)->toBe($account->id);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
})->with(['binding', 'authority']);

it('rolls back terminal replacement when the target is already assigned to another service', function (): void {
    [, $instance, $account, , , $actor, $target] = reviewTerminatedBinding();
    Instance::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $target->id, 'upstream_instance_id' => '199']);
    $token = $instance->downstream_token;
    expect(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'terminate', 'Conflicting target must roll back', $actor, true))->toThrow(QueryException::class);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)->and($instance->fresh()->upstream_api_id)->toBe($account->id)
        ->and($instance->fresh()->downstream_token)->toBe($token);
    $this->assertDatabaseCount('instance_upstream_bindings', 0);
    $this->assertDatabaseMissing('audit_logs', ['action' => 'instance.hot_swap', 'subject_id' => $instance->id]);
    $this->assertDatabaseMissing('fulfillment_tasks', ['instance_id' => $instance->id, 'action' => 'register_callback']);
});

it('keeps an actually uncertain upgrade creation blocked after an explicit retry', function (): void {
    [, , $instance, , $state] = reviewDeliveryFixture();
    $task = reviewUpgradeTask($instance);
    $state->hook = fn ($path) => $path === '/upgrade/checkout_config_upgrade'
        ? Http::response(['status' => 400, '_transport_failure' => true]) : null;
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->fresh()->payload['operation']['state'])->toBe('create_uncertain');
    app(RetryFulfillmentTaskAction::class)->execute($task->fresh(), 'Inspect existing creation without replay');
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($state->calls['/upgrade/checkout_config_upgrade'])->toBe(1)
        ->and($state->calls['/apply_credit'] ?? 0)->toBe(0);
});

it('discards callback snapshots if the upstream account changes during authentication or header I/O', function (string $change, string $endpoint): void {
    [, , $instance, $account, $state] = reviewDeliveryFixture();
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id]);
    $state->domainstatus = 'Deleted';
    $state->hook = function ($path) use ($account, $change, $endpoint) {
        if ($path === $endpoint) {
            $account->update(match ($change) {
                'endpoint' => ['base_url' => 'https://replacement.example.test'],
                'disabled' => ['is_active' => false],
                'secret' => ['encrypted_secret' => 'replacement-secret'],
                'username' => ['username' => 'replacement@example.test'],
                'auth_mode' => ['auth_header_mode' => 'raw_jwt'],
                'config' => ['config' => ['allow_credit_limit' => true]],
            });
        }

        return null;
    };
    app(ReconcileHostSyncAction::class)->execute($instance->id, $account->id, '99', $instance->downstream_token);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->fresh()->encrypted_credentials)->toBe(['password' => 'original-password'])
        ->and($instance->fresh()->last_synced_at)->toBeNull()
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
})->with(['endpoint', 'disabled', 'secret', 'username', 'auth_mode', 'config'])->with(['/zjmf_api_login', '/host/header']);

it('applies an authenticated callback snapshot when the account and binding remain unchanged', function (): void {
    [, , $instance, $account, $state] = reviewDeliveryFixture();
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id]);
    $state->domainstatus = 'Deleted';
    app(ReconcileHostSyncAction::class)->execute($instance->id, $account->id, '99', $instance->downstream_token);
    expect($instance->fresh()->status)->toBe(InstanceStatus::DELETED)
        ->and($instance->fresh()->encrypted_credentials)->toBe(['password' => 'upstream-password'])
        ->and($instance->fresh()->last_synced_at)->not->toBeNull()
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.upstream_sync', 'subject_id' => $instance->id]);
});
