<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class RecoverSub2ApiCardAction
{
    /** Trusted CLI only: the operator confirms the upstream code belongs to this exact request. */
    public function execute(FulfillmentTask $task, int $codeId, string $reason, bool $confirmed): void
    {
        $candidate = FulfillmentTask::findOrFail($task->id);
        $intent = $candidate->payload['remote_delivery'] ?? [];
        if (! $confirmed || trim($reason) === '' || $codeId < 1 || $candidate->status !== FulfillmentTaskStatus::FAILED
            || ! isset($intent['face_value'], $intent['base_url']) || isset($intent['encrypted_result'])) {
            throw new DomainException('Recovery requires a failed remote request, code ownership confirmation and a reason.');
        }
        $driver = app(DriverManager::class)->sub2ApiAccount($candidate->upstream_api_id);
        $instance = $candidate->instance()->firstOrFail();
        // The account endpoint is checked against the original purchase target before any lookup.
        $account = $instance->upstreamApi()->firstOrFail();
        if (rtrim($account->base_url, '/') !== $intent['base_url']) {
            throw new DomainException('Restore the original account endpoint before reconciliation.');
        }
        $result = app(CallUpstreamAction::class)->execute(fn () => $driver->recover($instance, $codeId, $intent['face_value']));
        if (! $result->success) {
            throw new DomainException($result->errorMessage);
        }
        DB::transaction(function () use ($candidate, $intent, $codeId, $result, $reason): void {
            $invoice = Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            $instance = Instance::whereKey($candidate->instance_id)->lockForUpdate()->firstOrFail();
            $locked = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::PAID || $invoice->instance_id !== $instance->id
                || $locked->status !== FulfillmentTaskStatus::FAILED || $locked->payload['remote_delivery'] !== $intent
                || $locked->driver_type !== $instance->driver_type || $locked->upstream_api_id !== $instance->upstream_api_id
                || ! in_array($instance->status, [InstanceStatus::PENDING, InstanceStatus::PROVISION_FAILED], true) || $instance->encrypted_credentials !== null) {
                throw new DomainException('Payment, delivery or binding changed during reconciliation.');
            }
            $payload = $locked->payload;
            $payload['remote_delivery']['state'] = 'received';
            $payload['remote_delivery']['encrypted_result'] = Crypt::encryptString(json_encode([
                'upstream_id' => $result->upstreamInstanceId, 'credentials' => $result->credentials,
            ], JSON_THROW_ON_ERROR));
            $locked->update(['payload' => $payload]);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'remote_card.recover', 'subject_type' => Instance::class,
                'subject_id' => $instance->id, 'reason' => $reason, 'after' => json_encode(['task_id' => $locked->id, 'upstream_id' => $codeId]),
                'created_at' => now(), 'updated_at' => now()]);
        });
        app(RetryFulfillmentTaskAction::class)->execute($candidate->fresh(), $reason);
    }
}
