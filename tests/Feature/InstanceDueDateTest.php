<?php

declare(strict_types=1);

use App\Actions\ApplyInstanceSnapshotAction;
use App\Actions\AssignUpstreamRoleAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Actions\ReconcileHostSyncAction;
use App\Actions\RequestInstanceOperationAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-11-18T08:00:00+08:00'));
});

function dueBoundaryFixture(int|string|null $due = 1794974400): array
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => 17, 'markup_percent' => '0.00']);
    $instance = Instance::factory()->create(['user_id' => User::factory()->create(['credit' => '100.00'])->id,
        'product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_instance_id' => '99', 'status' => InstanceStatus::ACTIVE, 'next_due_at' => '2026-11-18 04:00:00']);
    $state = (object) ['due' => $due, 'status' => 'Active', 'cycle' => 'monthly', 'renewedDue' => null];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/apply_credit') {
            $state->due = $state->renewedDue;
        }
        if ($path === '/provision/default') {
            $state->status = $request['func'] === 'suspend' ? 'Suspended' : 'Active';
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-timezone-jwt'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => $state->status,
                'nextduedate' => $state->due, 'productid' => 17, 'billingcycle' => $state->cycle,
                'suspendreason' => 'Service overdue'], 'currency' => ['code' => 'CNY']]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => $state->cycle], 'currency' => ['code' => 'CNY']]],
            '/host/renew' => ['status' => 200, 'data' => ['invoiceid' => 765]],
            '/use_credit_page' => ['status' => 200, 'data' => ['total' => '10.00', 'currency' => ['code' => 'CNY']]],
            '/apply_credit' => ['status' => 1001],
            '/provision/default' => ['status' => 200],
            '/cart/get_product_config' => ['status' => 200, 'data' => ['products' => ['pay_type' => []]]],
            default => throw new RuntimeException('Unexpected timezone test request: '.$path),
        });
    });

    return [$instance, $state];
}

it('preserves the upstream instant through snapshot persistence and reload', function (int|string $due, InstanceStatus $status): void {
    [$instance] = dueBoundaryFixture($due);
    $instance->update(['status' => $status]);
    $snapshot = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id)->syncInfo($instance);
    expect($snapshot->nextDueDate)->toMatch('/(?:Z|[+-]\d{2}:\d{2})$/');
    expect(Carbon::parse($snapshot->nextDueDate)->getTimestamp())->toBe(1794974400);

    expect(app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot))->toBeTrue();
    $current = $instance->fresh();
    expect($current->getRawOriginal('next_due_at'))->toBe('2026-11-18 12:00:00')
        ->and($current->next_due_at->toIso8601String())->toBe('2026-11-18T12:00:00+08:00')
        ->and($current->next_due_at->getTimestamp())->toBe(1794974400)
        ->and($current->status)->toBe(InstanceStatus::ACTIVE);
})->with([1794974400, '1794974400', '2026-11-18T04:00:00Z', '2026-11-18T12:00:00+08:00',
    '2026-11-18T09:30:00+05:30', '2026-11-18 12:00:00'])->with([InstanceStatus::PENDING, InstanceStatus::ACTIVE]);

it('does not fabricate a due boundary when the upstream date is absent', function (int|string|null $due): void {
    [$instance] = dueBoundaryFixture($due);
    $original = $instance->fresh()->getRawOriginal('next_due_at');
    $snapshot = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id)->syncInfo($instance);
    expect($snapshot->nextDueDate)->toBeNull();
    app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot);
    expect($instance->fresh()->getRawOriginal('next_due_at'))->toBe($original);
})->with([null, 0, '0', '', '   ']);

it('persists the authenticated callback requery using the application storage timezone', function (): void {
    [$instance] = dueBoundaryFixture();
    app(ReconcileHostSyncAction::class)->execute($instance->id, $instance->upstream_api_id, $instance->upstream_instance_id, $instance->downstream_token);
    expect($instance->fresh()->next_due_at->getTimestamp())->toBe(1794974400)
        ->and($instance->fresh()->getRawOriginal('next_due_at'))->toBe('2026-11-18 12:00:00');
});

it('normalizes the hot swap target due instant before storing the new binding', function (): void {
    [$instance] = dueBoundaryFixture();
    $target = UpstreamApi::factory()->create();
    $actor = User::factory()->create();
    app(AssignUpstreamRoleAction::class)->execute($actor, 'upstream-migrator', 'Isolated timezone test');
    app(HotSwapInstanceDriverAction::class)->execute($instance, $target->id, '199', '17', 'keep_orphan', 'Verify target due instant', $actor, true);
    expect($instance->fresh()->upstream_api_id)->toBe($target->id)
        ->and($instance->fresh()->next_due_at->getTimestamp())->toBe(1794974400)
        ->and($instance->fresh()->getRawOriginal('next_due_at'))->toBe('2026-11-18 12:00:00');
});

it('requests automatic suspension only after the authenticated noon boundary has passed', function (): void {
    [$instance] = dueBoundaryFixture();
    DB::table('system_settings')->insert(['group' => 'billing', 'key' => 'renewal_notice_days', 'value' => '0']);
    app(ProcessInstanceLifecycleAction::class)->execute();
    expect(FulfillmentTask::count())->toBe(0)->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);

    $this->travelTo(Carbon::parse('2026-11-18T12:00:01+08:00'));
    app(ProcessInstanceLifecycleAction::class)->execute();
    $task = FulfillmentTask::sole();
    expect($task->action)->toBe('suspend');
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED)
        ->and($instance->fresh()->getRawOriginal('next_due_at'))->toBe('2026-11-18 12:00:00')
        ->and($instance->fresh()->getRawOriginal('overdue_since'))->toBe('2026-11-18 12:00:00');
});

it('rejects a queued overdue suspension when the upstream due boundary was renewed', function (): void {
    [$instance, $state] = dueBoundaryFixture();
    $this->travelTo(Carbon::parse('2026-11-18T12:00:01+08:00'));
    $instance->update(['next_due_at' => '2026-11-18 12:00:00']);
    $task = app(RequestInstanceOperationAction::class)->execute($instance, 'suspend', 'Old overdue request', 'old-noon-boundary', source: 'cron_overdue');
    $state->due = 1794978000;
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Http::assertNotSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/provision/default');
});

it('restores an owned overdue suspension when the authenticated due instant is still in the future', function (): void {
    [$instance, $state] = dueBoundaryFixture();
    $instance->update(['status' => InstanceStatus::SUSPENDED, 'active_config' => ['overdue_suspension' => true, 'overdue_suspend_reason' => 'Service overdue']]);
    $state->status = 'Suspended';
    app(ProcessInstanceLifecycleAction::class)->execute();
    $task = FulfillmentTask::sole();
    expect($task->action)->toBe('unsuspend');
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->fresh()->next_due_at->getTimestamp())->toBe(1794974400);
});

it('keeps hourly daily and monthly renewal keys and delivery on the same UTC instant', function (BillingCycle $cycle, string $renewed): void {
    [$instance, $state] = dueBoundaryFixture();
    $instance->update(['billing_cycle' => $cycle]);
    $state->cycle = $cycle->value;
    $state->renewedDue = Carbon::parse($renewed)->getTimestamp();
    $invoice = app(CreateComboInvoiceAction::class)->renewal($instance->user, $instance, true);
    expect($invoice->billing_period_key)->toBe($instance->id.':renew:2026-11-18T04:00:00Z');
    $task = FulfillmentTask::sole();
    $context = json_decode(Crypt::decryptString($task->payload['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
    expect($context['due_boundary'])->toBe('2026-11-18T04:00:00Z')
        ->and($context['renew_until'])->toBe(Carbon::parse($renewed)->utc()->format('Y-m-d\TH:i:s\Z'));
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($instance->fresh()->next_due_at->getTimestamp())->toBe($state->renewedDue)
        ->and($instance->fresh()->getRawOriginal('next_due_at'))->toBe(Carbon::parse($renewed)->toDateTimeString());
})->with([[BillingCycle::HOUR, '2026-11-18T13:00:00+08:00'], [BillingCycle::DAY, '2026-11-19T12:00:00+08:00'],
    [BillingCycle::MONTHLY, '2026-12-18T12:00:00+08:00']]);
