<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\MaintenanceBatchWork;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\ProbeClassicProductJob;
use App\Jobs\ProcessInstanceLifecycleJob;
use App\Models\AffiliateCommission;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Services\SettingService;
use App\Support\FairMaintenanceBatch;
use App\Support\MachineOperation;
use App\Support\MaintenanceBudget;
use Illuminate\Support\Facades\Cache;
use LogicException;

class RunMaintenanceBatchAction implements MaintenanceBatchWork
{
    public function __construct(private readonly FairMaintenanceBatch $batch) {}

    public function execute(string $kind): array
    {
        $budget = new MaintenanceBudget;

        return match ($kind) {
            'invoices' => $this->batch->execute($kind, Invoice::where('status', InvoiceStatus::UNPAID)->where('expires_at', '<=', now()),
                'expires_at', fn (int $id) => app(ExpireUnpaidInvoicesAction::class)->executeOne($id), budget: $budget),
            'fulfillment' => $this->batch->execute($kind, FulfillmentTask::where(function ($query): void {
                $query->where(fn ($pending) => $pending->where('status', FulfillmentTaskStatus::PENDING)->where('created_at', '<', now()->subMinute())
                    ->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now())))
                    ->orWhere(fn ($running) => $running->where('status', FulfillmentTaskStatus::RUNNING)->where('lease_expires_at', '<', now()));
            }), 'created_at', fn (int $id) => app(DispatchStalePendingTasksAction::class)->executeOne($id), budget: $budget),
            'cards' => $this->batch->execute($kind, RedemptionCode::where('status', 'reserved')->where('reserved_until', '<', now()),
                'reserved_until', fn (int $id) => app(ReapRedemptionReservationsAction::class)->executeOne($id), budget: $budget),
            'affiliate-accrue' => $this->batch->execute($kind, Invoice::where('status', InvoiceStatus::PAID)->where('type', InvoiceType::NEW_ORDER)
                ->where('currency', 'CNY')->where('total_amount', '>', '0.00')->whereNotNull('line_items->affiliate')
                ->whereNotIn('id', AffiliateCommission::select('invoice_id'))
                ->whereNull('affiliate_zero_accrual_at'),
                'paid_at', fn (int $id) => app(AccrueAffiliateCommissionAction::class)->execute($id), budget: $budget),
            'affiliate-mature' => $this->batch->execute($kind, AffiliateCommission::where('status', 'pending')->where('available_at', '<=', now()),
                'available_at', fn (int $id) => app(MatureAffiliateCommissionsAction::class)->execute($id), budget: $budget),
            'lifecycle' => $this->lifecycle($budget), 'catalog' => $this->catalog(),
            'notifications' => app(DispatchPendingNotificationsAction::class)->run($budget),
            default => throw new LogicException('Unknown maintenance kind.'),
        };
    }

    private function lifecycle(MaintenanceBudget $budget): array
    {
        $this->assertAsynchronous();

        return Cache::lock('lifecycle-dispatch', 50)->get(function () use ($budget): array {
            $settings = app(SettingService::class);
            $base = Instance::whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])
                ->whereNotIn('billing_cycle', [BillingCycle::FREE, BillingCycle::ONETIME, BillingCycle::ONTRIAL]);
            $urgent = (clone $base)->whereNotNull('next_due_at')->where('next_due_at', '<=', now()->addDays(7));

            return $this->batch->execute('lifecycle', $base, 'last_synced_at', function (int $id): void {
                $instance = Instance::with('upstreamApi')->find($id);
                if ($instance === null || ! in_array($instance->status, [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED], true)
                    || in_array($instance->billing_cycle, [BillingCycle::FREE, BillingCycle::ONETIME, BillingCycle::ONTRIAL], true)) {
                    return;
                }
                ProcessInstanceLifecycleJob::dispatch($id, app(MachineOperation::class)->binding($instance))
                    ->onQueue($instance->next_due_at !== null && $instance->next_due_at->lte(now()->addDays(7)) ? 'lifecycle-urgent' : 'lifecycle');
            }, budget: $budget, priorityQuery: $urgent,
                priorityLimit: min(50, max(1, $settings->getInt('lifecycle.urgent_batch', 100))),
                rotationLimit: min(50, max(1, $settings->getInt('lifecycle.rotation_batch', 100))));
        }) ?? ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'units' => 0, 'deferred' => 0, 'budget_exhausted' => false];
    }

    private function catalog(): array
    {
        $this->assertAsynchronous();

        return Cache::lock('catalog-dispatch', 50)->get(function (): array {
            $budget = new MaintenanceBudget(limit: min(100, max(1, app(SettingService::class)->getInt('lifecycle.catalog_batch', 100))));

            return $this->batch->execute('catalog', Product::where('driver_type', DriverType::ZJMF_API)
                ->whereHas('upstreamApi', fn ($query) => $query->where('is_active', true)), 'id',
                fn (int $id) => ProbeClassicProductJob::dispatch($id)->onQueue('catalog'), budget: $budget);
        }) ?? ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'units' => 0, 'deferred' => 0, 'budget_exhausted' => false];
    }

    private function assertAsynchronous(): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Lifecycle and catalog maintenance require asynchronous queues.');
        }
    }
}
