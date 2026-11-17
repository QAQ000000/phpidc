<?php

declare(strict_types=1);

use App\Actions\ProvisionInstanceAction;
use App\Actions\ReapRedemptionReservationsAction;
use App\Actions\RefundInvoiceAction;
use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\RedemptionCode;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function lifecycleRefundFixture(?InstanceStatus $state): array
{
    $buyer = User::factory()->create(['credit' => '50.00', 'frozen_credit' => '6.00']);
    $instance = $state === null ? null : Instance::factory()->create(['user_id' => $buyer->id, 'status' => $state]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $instance?->id]);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $user, Invoice $subject): bool => $user->is($actor) && $subject->is($invoice));

    return [$invoice, $actor, $buyer, $instance];
}

it('requires confirmed termination even when the operator has refund permission', function (?InstanceStatus $state): void {
    [$invoice, $actor, $buyer, $instance] = lifecycleRefundFixture($state);
    expect(Gate::forUser($actor)->allows('refund-invoice', $invoice))->toBeTrue();
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Authorized operator without service waiver', actor: $actor))
        ->toThrow(DomainException::class, 'Service termination must be confirmed before refund');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($buyer->fresh()->credit)->toBe('50.00')
        ->and($buyer->fresh()->frozen_credit)->toBe('6.00')->and($instance?->fresh()->status)->toBe($state);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('audit_logs', 0);
})->with([InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED, InstanceStatus::PENDING, InstanceStatus::PROVISION_FAILED, InstanceStatus::FRAUD, null]);

it('allows an authorized refund after confirmed cancellation or deletion without a service waiver', function (InstanceStatus $state): void {
    [$invoice, $actor, $buyer, $instance] = lifecycleRefundFixture($state);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Termination already confirmed', actor: $actor);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)->and($buyer->fresh()->credit)->toBe('60.00')
        ->and($buyer->fresh()->frozen_credit)->toBe('6.00')->and($instance->fresh()->status)->toBe($state);
    $this->assertDatabaseCount('financial_transactions', 1);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'action' => 'invoice.refund', 'reason' => 'Termination already confirmed']);
})->with([InstanceStatus::CANCELLED, InstanceStatus::DELETED]);

it('rejects a service belonging to another buyer even when keepService is authorized', function (): void {
    [$invoice, $actor, $buyer, $instance] = lifecycleRefundFixture(InstanceStatus::ACTIVE);
    $instance->update(['user_id' => User::factory()->create()->id]);
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Invalid ownership', actor: $actor))->toThrow(DomainException::class);
    expect($buyer->fresh()->credit)->toBe('50.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('does not extend a refund grant to another invoice owned by the same customer', function (): void {
    [$allowed, $actor, $buyer] = lifecycleRefundFixture(InstanceStatus::CANCELLED);
    $other = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $allowed->instance_id]);
    expect(fn () => app(RefundInvoiceAction::class)->execute($other, 'refund_wallet', reason: 'Grant is for another invoice', actor: $actor))->toThrow(DomainException::class, 'Refund is not authorized');
    expect($buyer->fresh()->credit)->toBe('50.00')->and($other->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('discards a card result when a refund invalidates the running worker between reservation and delivery', function (): void {
    [$invoice, $actor, , $instance] = lifecycleRefundFixture(InstanceStatus::PENDING);
    $code = RedemptionCode::factory()->create(['product_id' => $instance->product_id, 'code' => 'NEVER-DELIVER-AFTER-REFUND']);
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::RUNNING,
        'locked_by' => 'old-worker', 'locked_at' => now(), 'lease_expires_at' => now()->addSeconds(210)]);
    $driver = Mockery::mock(RedemptionCodeDriver::class)->makePartial();
    $driver->shouldReceive('provision')->once()->andReturnUsing(function () use ($invoice, $actor): ProvisionResult {
        app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', keepService: true, reason: 'Refund during fulfillment', actor: $actor);

        return ProvisionResult::ok(credentials: ['card_key' => 'NEVER-DELIVER-AFTER-REFUND']);
    });
    app()->instance(RedemptionCodeDriver::class, $driver);
    $result = app(ProvisionInstanceAction::class)->execute($instance, [], $task->id, 'old-worker');
    expect($result->success)->toBeFalse()->and($result->errorMessage)->toBe('Task lease changed before delivery.')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::STALE)
        ->and($instance->fresh()->encrypted_credentials)->toBeNull()->and($code->fresh()->status)->toBe('reserved');
    $this->travel(16)->minutes();
    app(ReapRedemptionReservationsAction::class)->execute();
    expect($code->fresh()->status)->toBe('available')->and($code->fresh()->instance_id)->toBeNull();
    expect(FinancialTransaction::where('reference_key', 'refund:'.$invoice->id)->count())->toBe(1);
});
