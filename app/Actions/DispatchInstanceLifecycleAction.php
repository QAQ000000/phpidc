<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\InstanceStatus;
use App\Jobs\ProcessInstanceLifecycleJob;
use App\Models\Instance;
use App\Services\SettingService;
use App\Support\MachineOperation;
use Illuminate\Support\Facades\Cache;
use LogicException;

class DispatchInstanceLifecycleAction
{
    public function execute(): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Lifecycle dispatch requires an asynchronous queue.');
        }

        return Cache::lock('lifecycle-dispatch', 50)->get(function (): int {
            $settings = app(SettingService::class);
            $urgentLimit = min(100, max(1, $settings->getInt('lifecycle.urgent_batch', 100)));
            $rotationLimit = min(100, max(1, $settings->getInt('lifecycle.rotation_batch', 100)));
            $base = Instance::whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])
                ->whereNotIn('billing_cycle', [BillingCycle::FREE, BillingCycle::ONETIME, BillingCycle::ONTRIAL]);
            $urgent = (clone $base)->whereNotNull('next_due_at')->where('next_due_at', '<=', now()->addDays(7))
                ->orderByRaw('CASE WHEN last_synced_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_synced_at')->orderBy('id')
                ->limit($urgentLimit)->with('upstreamApi')->get();
            $cursor = (int) Cache::get('lifecycle-cursor', 0);
            $rotation = (clone $base)->where('id', '>', $cursor)->orderBy('id')->limit($rotationLimit)->with('upstreamApi')->get();
            if ($rotation->isEmpty() && $cursor > 0) {
                $rotation = (clone $base)->orderBy('id')->limit($rotationLimit)->with('upstreamApi')->get();
            }
            foreach ($urgent->merge($rotation) as $instance) {
                ProcessInstanceLifecycleJob::dispatch($instance->id, app(MachineOperation::class)->binding($instance))
                    ->onQueue($urgent->contains('id', $instance->id) ? 'lifecycle-urgent' : 'lifecycle');
            }
            Cache::forever('lifecycle-cursor', $rotation->last()->id ?? 0);

            return $urgent->merge($rotation)->count();
        }) ?? 0;
    }
}
