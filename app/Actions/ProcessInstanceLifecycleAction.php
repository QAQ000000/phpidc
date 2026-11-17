<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Services\DriverManager;
use App\Services\SettingService;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProcessInstanceLifecycleAction
{
    public function execute(): int
    {
        $count = 0;
        foreach (Instance::whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])->whereNotNull('next_due_at')->lazyById(100) as $instance) {
            if (! $instance->billing_cycle->isRenewable()) {
                continue;
            }
            try {
                $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
                if (! $driver instanceof RenewableResource) {
                    continue;
                }
                $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
                if (! app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot)) {
                    continue;
                }
                $instance = $instance->fresh();
                if ($instance->status->isTerminal() || $instance->next_due_at === null || isset($instance->active_config['cancellation'])) {
                    continue;
                }
                $boundary = $instance->next_due_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');
                if ($instance->next_due_at->isFuture()) {
                    if ($instance->status === InstanceStatus::SUSPENDED && ($instance->active_config['overdue_suspension'] ?? false)
                        && $snapshot->suspendReason !== null && $snapshot->suspendReason === ($instance->active_config['overdue_suspend_reason'] ?? null)) {
                        app(RequestInstanceOperationAction::class)->execute($instance, 'unsuspend', 'Confirmed renewal of an overdue suspension', hash('sha256', $boundary), source: 'cron_overdue');
                    } else {
                        $seconds = max(0, app(SettingService::class)->getInt('billing.renewal_notice_days', 7)) * 86400;
                        if ($instance->billing_cycle->value === 'hour') {
                            $seconds = min($seconds, 3600);
                        }
                        if ($instance->billing_cycle->value === 'day') {
                            $seconds = min($seconds, 86400);
                        }
                        if ($instance->next_due_at->lte(now()->addSeconds($seconds))) {
                            app(CreateComboInvoiceAction::class)->renewal($instance->user()->firstOrFail(), $instance);
                        }
                    }
                } elseif ($instance->status === InstanceStatus::ACTIVE) {
                    app(RequestInstanceOperationAction::class)->execute($instance, 'suspend', 'Service overdue', hash('sha256', $boundary), source: 'cron_overdue');
                }
                // Upstream-proxied hosts never auto-terminate; only an authenticated Deleted snapshot does that (INV-S2).
                $count++;
            } catch (DomainException|ValidationException $e) {
                Log::warning('Instance lifecycle requires attention.', ['instance_id' => $instance->id, 'error' => $e->getMessage()]);
            }
        }

        return $count;
    }
}
