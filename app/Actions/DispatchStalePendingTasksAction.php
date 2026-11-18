<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus as Status;
use App\Enums\InvoiceStatus;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DispatchStalePendingTasksAction
{
    public function execute(): void
    {
        FulfillmentTask::where(function ($query): void {
            $query->where(fn ($q) => $q->where('status', Status::PENDING)->where('created_at', '<', now()->subMinute())
                ->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now())))
                ->orWhere(fn ($q) => $q->where('status', Status::RUNNING)->where('lease_expires_at', '<', now()));
        })->select(['id', 'instance_id', 'invoice_id'])->chunkById(100, function ($tasks): void {
            foreach ($tasks as $candidate) {
                DB::transaction(function () use ($candidate): void {
                    $invoice = $candidate->invoice_id === null ? null : Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
                    $instance = $candidate->instance_id === null ? null : Instance::whereKey($candidate->instance_id)->lockForUpdate()->first();
                    $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                    $pending = $task->status === Status::PENDING && $task->created_at->lt(now()->subMinute())
                        && ($task->available_at === null || $task->available_at->lte(now()));
                    $expired = $task->status === Status::RUNNING && $task->lease_expires_at !== null && $task->lease_expires_at->isPast();
                    if (! $pending && ! $expired) {
                        return;
                    }
                    if ($instance === null || $task->instance_id !== $instance->id || $task->invoice_id !== $candidate->invoice_id
                        || ($invoice !== null && ($invoice->status !== InvoiceStatus::PAID || $invoice->instance_id !== $instance->id))
                        || $instance->status->isTerminal() || $task->driver_type !== $instance->driver_type || $task->upstream_api_id !== $instance->upstream_api_id) {
                        $task->update(['status' => Status::STALE, 'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null]);

                        return;
                    }
                    if ($task->attempts >= $task->max_attempts) {
                        $task->update(['status' => Status::FAILED, 'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null,
                            'error_message' => 'Task recovery attempts exhausted.']);
                        DB::afterCommit(fn () => Log::error('Fulfillment recovery exhausted.', ['task_id' => $task->id]));

                        return;
                    }
                    // Due pending tasks stay due. Only a lost running lease needs a new backoff.
                    $available = $task->available_at;
                    if ($expired) {
                        $available = now()->addSeconds([60, 300, 900][min(max($task->attempts - 1, 0), 2)]);
                        $task->update(['status' => Status::PENDING, 'locked_by' => null, 'locked_at' => null,
                            'lease_expires_at' => null, 'available_at' => $available]);
                    }
                    DB::afterCommit(function () use ($task, $available): void {
                        $key = 'fulfillment-recovery-dispatch:'.$task->id.':'.$task->attempts;
                        if (! Cache::add($key, true, 60)) {
                            return;
                        }
                        $sent = false;
                        try {
                            Bus::dispatch((new ExecuteFulfillmentTaskJob($task->id))->delay($available));
                            $sent = true;
                        } finally {
                            if (! $sent) {
                                Cache::forget($key);
                            }
                        }
                    });
                });
            }
        });
    }
}
