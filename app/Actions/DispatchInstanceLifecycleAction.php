<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\InstanceStatus;
use App\Jobs\ProcessInstanceLifecycleJob;
use App\Models\Instance;
use App\Services\SettingService;
use App\Support\FairMaintenanceBatch;
use App\Support\MachineOperation;
use App\Support\MaintenanceBudget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use LogicException;

class DispatchInstanceLifecycleAction
{
    public function execute(): int
    {
        $this->assertAsynchronous();

        return Cache::lock('lifecycle-dispatch', 50)->get(function (): int {
            $settings = app(SettingService::class);
            $urgentLimit = min(100, max(1, $settings->getInt('lifecycle.urgent_batch', 100)));
            $rotationLimit = min(100, max(1, $settings->getInt('lifecycle.rotation_batch', 100)));
            $base = $this->eligible();
            $urgent = (clone $base)->whereNotNull('next_due_at')->where('next_due_at', '<=', now()->addDays(7))
                ->orderByRaw('CASE WHEN last_synced_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_synced_at')->orderBy('id')
                ->limit($urgentLimit)->with('upstreamApi')->get();
            $cursor = (int) Cache::get('lifecycle-cursor', 0);
            $rotation = (clone $base)->where('id', '>', $cursor)->orderBy('id')->limit($rotationLimit)->with('upstreamApi')->get();
            if ($rotation->isEmpty() && $cursor > 0) {
                $rotation = (clone $base)->orderBy('id')->limit($rotationLimit)->with('upstreamApi')->get();
            }
            foreach ($urgent->merge($rotation) as $instance) {
                $this->dispatch($instance, $urgent->contains('id', $instance->id));
            }
            Cache::forever('lifecycle-cursor', $rotation->last()->id ?? 0);

            return $urgent->merge($rotation)->count();
        }) ?: 0;
    }

    public function executeMaintenance(MaintenanceBudget $budget): array
    {
        $this->assertAsynchronous();

        return Cache::lock('lifecycle-dispatch', 50)->get(function () use ($budget): array {
            $settings = app(SettingService::class);
            $base = $this->eligible();
            $urgent = (clone $base)->whereNotNull('next_due_at')->where('next_due_at', '<=', now()->addDays(7));

            return app(FairMaintenanceBatch::class)->execute('lifecycle', $base, 'last_synced_at', function (int $id): void {
                $instance = $this->eligible()->with('upstreamApi')->find($id);
                if ($instance === null) {
                    return;
                }
                $this->dispatch($instance, $instance->next_due_at !== null && $instance->next_due_at->lte(now()->addDays(7)));
            }, budget: $budget, priorityQuery: $urgent,
                priorityLimit: min(50, max(1, $settings->getInt('lifecycle.urgent_batch', 100))),
                rotationLimit: min(50, max(1, $settings->getInt('lifecycle.rotation_batch', 100))));
        }) ?: ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'units' => 0, 'deferred' => 0, 'budget_exhausted' => false];
    }

    /** @return Builder<Instance> */
    private function eligible(): Builder
    {
        return Instance::whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])
            ->whereNotIn('billing_cycle', [BillingCycle::FREE, BillingCycle::ONETIME, BillingCycle::ONTRIAL]);
    }

    private function dispatch(Instance $instance, bool $urgent): void
    {
        ProcessInstanceLifecycleJob::dispatch($instance->id, app(MachineOperation::class)->binding($instance))
            ->onQueue($urgent ? 'lifecycle-urgent' : 'lifecycle');
    }

    private function assertAsynchronous(): void
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Lifecycle dispatch requires an asynchronous queue.');
        }
    }
}
