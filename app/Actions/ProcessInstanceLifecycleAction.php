<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\RenewableResource;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\MachineOperation;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ProcessInstanceLifecycleAction
{
    /** Synchronous library entry; the minute scheduler dispatches individual jobs instead. */
    public function execute(): int
    {
        $count = 0;
        foreach (Instance::whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])->whereNotNull('next_due_at')->lazyById(100) as $instance) {
            $count += (int) $this->process($instance);
        }

        return $count;
    }

    public function process(Instance $instance): bool
    {
        if (! $instance->status->isBillable() || ! $instance->billing_cycle->isRenewable()) {
            return false;
        }
        try {
            $driver = app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id);
            if (! $driver instanceof RenewableResource) {
                return false;
            }
            $binding = app(MachineOperation::class)->binding($instance);
            $accountAuth = app(MachineOperation::class)->accountFingerprint($instance);
            $snapshot = app(CallUpstreamAction::class)->execute(fn () => $driver->syncInfo($instance));
            $current = $instance->fresh(['upstreamApi']);
            if (! $current->upstreamApi?->is_active || app(MachineOperation::class)->binding($current) !== $binding
                || app(MachineOperation::class)->accountFingerprint($current) !== $accountAuth) {
                return false;
            }
            // Capture the applied row before releasing its lock: a later change
            // must invalidate this handoff rather than become part of the snapshot.
            $instance = DB::transaction(function () use ($instance, $snapshot): ?Instance {
                return app(ApplyInstanceSnapshotAction::class)->execute($instance, $snapshot) ? $instance->fresh() : null;
            });
            if ($instance === null) {
                return false;
            }
            if ($instance->status->isTerminal() || $instance->next_due_at === null || isset($instance->active_config['cancellation'])) {
                return false;
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
                        // The authenticated boundary is already known; do not re-sync an existing invoice.
                        if (! Invoice::where('billing_period_key', $instance->id.':renew:'.$boundary)->exists()) {
                            app(CreateComboInvoiceAction::class)->automaticRenewalFromLifecycle($instance->user()->firstOrFail(), $instance, $binding, $accountAuth);
                        }
                    }
                }
            } elseif ($instance->status === InstanceStatus::ACTIVE) {
                app(RequestInstanceOperationAction::class)->execute($instance, 'suspend', 'Service overdue', hash('sha256', $boundary), source: 'cron_overdue');
            }

            // Upstream-proxied hosts never auto-terminate; only an authenticated Deleted snapshot does that (INV-S2).
            return true;
        } catch (DomainException|ValidationException $e) {
            Log::warning('Instance lifecycle requires attention.', ['instance_id' => $instance->id, 'error_class' => get_class($e)]);
        }

        return false;
    }
}
