<?php

declare(strict_types=1);

use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\SuspendInstanceAction;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Jobs\ReconcileHostSyncJob;
use App\Models\AffiliateCommission;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| SPECIFICATIONS — NOT PART OF THE DEFAULT TEST RUN
|--------------------------------------------------------------------------
|
| These cases describe behaviour that does not exist yet. They are kept out of
| the default `pest` run so that a red suite always means a real regression,
| never "phase 1 is not written yet".
|
| Run them deliberately:
|
|     ./vendor/bin/pest tests/Specs
|
| Rules for agents (docs/agent-guardrails.md):
|
|  - Deleting or weakening a case here is forbidden. tests/Unit/SpecIntegrityTest
|    asserts the exact case count of this file.
|  - When a case turns green, move it to tests/Feature and update the count in
|    SpecIntegrityTest. That is the only sanctioned way to reduce it.
|
*/

uses(RefreshDatabase::class);

it('fails a task permanently once max_attempts is exhausted', function (): void {
    $task = FulfillmentTask::factory()->create([
        'status' => 'running',
        'attempts' => 5,
        'max_attempts' => 5,
        'lease_expires_at' => now()->subMinute(),
    ]);

    app(DispatchStalePendingTasksAction::class)->execute();

    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('verifies zjmf callback signature, blocks rand_str replay, and never clears suspend_reason', function (): void {
    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('a', 32),
        'suspend_reason' => 'Overdue billing',
        'status' => InstanceStatus::SUSPENDED->value,
    ]);

    $params = ['id' => $instance->id, 'token' => $instance->downstream_token, 'rand_str' => 'AbCd12'];
    ksort($params, SORT_STRING);
    $signature = strtoupper(md5(json_encode($params)));

    $payload = [
        'id' => $instance->id,
        'rand_str' => 'AbCd12',
        'signature' => $signature,
        'domainstatus' => 'Active',
        'suspendreason' => '',
    ];

    $this->postJson('/api/host/sync', $payload)->assertOk();
    expect($instance->fresh()->suspend_reason)->toBe('Overdue billing');

    // Same rand_str replays: rejected (INV-Q4).
    $this->postJson('/api/host/sync', $payload)->assertStatus(400);

    // A different instance may reuse the same rand_str (key is scoped to instance).
    $other = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('b', 32),
    ]);
    $otherParams = ['id' => $other->id, 'token' => $other->downstream_token, 'rand_str' => 'AbCd12'];
    ksort($otherParams, SORT_STRING);

    $this->postJson('/api/host/sync', [
        'id' => $other->id,
        'rand_str' => 'AbCd12',
        'signature' => strtoupper(md5(json_encode($otherParams))),
        'domainstatus' => 'Active',
    ])->assertOk();
});

it('responds to host sync before reconciling upstream', function (): void {
    Http::fake(['*/host/header*' => Http::response(['status' => 200, 'data' => ['host_data' => []]])]);
    Queue::fake();

    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'downstream_token' => str_repeat('c', 32),
    ]);
    $params = ['id' => $instance->id, 'token' => $instance->downstream_token, 'rand_str' => 'Zz12Ab'];
    ksort($params, SORT_STRING);

    $this->postJson('/api/host/sync', [
        'id' => $instance->id,
        'rand_str' => 'Zz12Ab',
        'signature' => strtoupper(md5(json_encode($params))),
    ])->assertOk();

    // INV-Q3: the handler must not perform the upstream round trip inline.
    Http::assertNothingSent();
    Queue::assertPushed(ReconcileHostSyncJob::class);
});

it('pre-syncs upstream status before suspend and reconciles idempotently if already suspended', function (): void {
    Http::fake([
        '*/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'a.b.c']),
        '*/host/header*' => Http::response([
            'status' => 200,
            'data' => ['host_data' => ['domainstatus' => 'Suspended', 'suspendreason' => 'Upstream expired']],
        ]),
        '*/provision/default' => Http::response(['status' => 400, 'msg' => 'Should not be called'], 400),
    ]);

    $instance = Instance::factory()->create([
        'driver_type' => 'zjmf_api',
        'upstream_instance_id' => '999',
        'status' => InstanceStatus::ACTIVE->value,
    ]);

    $result = app(SuspendInstanceAction::class)->execute($instance, 'Overdue');

    expect($result->success)->toBeTrue()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::SUSPENDED);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/provision/default'));
});

it('claws back frozen affiliate commission on refund and records expense on external refund', function (): void {
    $referrer = User::factory()->create(['affiliate_balance' => '0.00']);
    $buyer = User::factory()->create(['referred_by' => $referrer->id]);
    $invoice = Invoice::factory()->create([
        'user_id' => $buyer->id,
        'total_amount' => '100.00',
        'status' => InvoiceStatus::PAID->value,
    ]);
    $admin = User::factory()->create();
    $reason = 'Refund approved while retaining the service.';
    Gate::define('refund-invoice', fn (User $actor, Invoice $subject): bool => $actor->is($admin) && $subject->is($invoice));
    $this->actingAs($admin);
    $commission = AffiliateCommission::factory()->create([
        'referrer_id' => $referrer->id,
        'buyer_id' => $buyer->id,
        'invoice_id' => $invoice->id,
        'rate_snapshot' => '10.00',
        'amount' => '10.00',
        'status' => 'pending',
    ]);

    app(RefundInvoiceAction::class)->execute(
        $invoice,
        mode: 'external_record',
        keepService: true,
        reason: $reason,
        actor: $admin,
    );

    expect($commission->fresh()->status)->toBe('clawed_back');

    $this->assertDatabaseHas('financial_transactions', [
        'invoice_id' => $invoice->id,
        'type' => 'expense',
        'amount' => '100.00',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'invoice.refund',
        'subject_type' => Invoice::class,
        'subject_id' => $invoice->id,
        'reason' => $reason,
    ]);
});

it('refuses to refund while the instance is still running', function (): void {
    $user = User::factory()->create();
    $instance = Instance::factory()->create([
        'user_id' => $user->id,
        'status' => InstanceStatus::ACTIVE->value,
    ]);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'instance_id' => $instance->id,
        'total_amount' => '100.00',
        'status' => InvoiceStatus::PAID->value,
    ]);

    expect(fn () => app(RefundInvoiceAction::class)
        ->execute($invoice, mode: 'refund_wallet'))
        ->toThrow(DomainException::class);
});
