<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\Sub2ApiRemoteDriver;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\RedemptionCode;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProvisionRemoteCardAction
{
    public function execute(Instance $instance, Sub2ApiRemoteDriver $driver, array $payload, ?int $taskId, ?string $worker): ProvisionResult
    {
        if ($taskId === null || $worker === null) {
            return ProvisionResult::fail('Remote issuance requires a leased, durable fulfillment task.');
        }
        $candidate = FulfillmentTask::findOrFail($taskId);
        $prepared = DB::transaction(function () use ($candidate, $instance, $worker): ?array {
            $invoice = Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! $this->current($invoice, $locked, $task, $instance, $worker)) {
                return null;
            }
            $payload = $task->payload ?? [];
            if (isset($payload['remote_delivery'])) {
                return ['instance' => $locked, 'payload' => $payload, 'send' => false];
            }
            if (! is_string($payload['encrypted_purchase'] ?? null)) {
                return null;
            }
            $purchase = json_decode(Crypt::decryptString($payload['encrypted_purchase']), true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($purchase['sub2api_face_value'] ?? null) || ! is_string($purchase['sub2api_base_url'] ?? null)) {
                return null;
            }
            $payload['remote_delivery'] = ['state' => 'requested', 'face_value' => $purchase['sub2api_face_value'],
                'base_url' => $purchase['sub2api_base_url'], 'upstream_api_id' => $locked->upstream_api_id,
                'idempotency_key' => 'cms-sub2api-'.Str::ulid(), 'requested_at' => now()->toIso8601String()];
            $task->update(['payload' => $payload]);
            $this->audit($locked, 'remote_card.request', ['task_id' => $task->id, 'face_value' => $purchase['sub2api_face_value']]);

            return ['instance' => $locked, 'payload' => $payload, 'send' => true];
        });
        if ($prepared === null) {
            return ProvisionResult::fail('Remote task, purchase snapshot or binding is no longer valid.');
        }
        if ($prepared['send']) {
            $result = app(CallUpstreamAction::class)->execute(fn () => $driver->provision($prepared['instance'],
                ['remote_delivery' => $prepared['payload']['remote_delivery'], 'invoice_id' => $candidate->invoice_id]));
            // Save evidence even if a refund, lease loss or hot swap occurred during HTTP. Never resend this intent.
            DB::transaction(function () use ($candidate, $result): void {
                $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $payload = $task->payload;
                if (isset($payload['remote_delivery']['encrypted_result'])) {
                    return;
                }
                $payload['remote_delivery']['state'] = $result->success ? 'received' : (($result->rawResponse['rejected'] ?? false) ? 'rejected' : 'uncertain');
                if ($result->success) {
                    $payload['remote_delivery']['encrypted_result'] = Crypt::encryptString(json_encode([
                        'upstream_id' => $result->upstreamInstanceId, 'credentials' => $result->credentials,
                    ], JSON_THROW_ON_ERROR));
                }
                $task->update(['payload' => $payload]);
            });
            if (! $result->success) {
                return $result;
            }
        }
        $latest = FulfillmentTask::findOrFail($taskId);
        if (! isset($latest->payload['remote_delivery']['encrypted_result'])) {
            return ProvisionResult::fail('Remote issuance requires manual reconciliation; the previous request will not be replayed.');
        }

        return $this->deliver($instance, $latest, $worker);
    }

    private function deliver(Instance $instance, FulfillmentTask $candidate, string $worker): ProvisionResult
    {
        $stored = json_decode(Crypt::decryptString($candidate->payload['remote_delivery']['encrypted_result']), true, flags: JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($instance, $candidate, $worker, $stored): ProvisionResult {
            $invoice = Invoice::whereKey($candidate->invoice_id)->lockForUpdate()->firstOrFail();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            // Every task mutation serializes on its instance first; reject obsolete workers before creating inventory.
            $task = FulfillmentTask::findOrFail($candidate->id);
            if (! $this->current($invoice, $locked, $task, $instance, $worker)) {
                Log::warning('Remote card result retained; manual reconciliation required.', ['task_id' => $task->id]);

                return ProvisionResult::fail('Remote result retained but payment, binding or lease changed; reconcile manually.');
            }
            if ($locked->encrypted_credentials !== null) {
                if ($locked->status === InstanceStatus::ACTIVE && $locked->upstream_instance_id === $stored['upstream_id']) {
                    return ProvisionResult::ok(upstreamInstanceId: $locked->upstream_instance_id, credentials: $locked->encrypted_credentials);
                }
                throw new DomainException('Existing credentials conflict with the remote card result.');
            }
            $credentials = $stored['credentials'];
            // INV-L1: claim the card row before locking the task; never make a remote card available for resale.
            $code = RedemptionCode::where('product_id', $locked->product_id)
                ->where('code_hash', hash_hmac('sha256', $credentials['card_key'], config('app.key')))->lockForUpdate()->first();
            if ($code !== null && $code->instance_id !== $locked->id) {
                throw new DomainException('Upstream returned a card already assigned to another delivery.');
            }
            if ($code === null) {
                $code = RedemptionCode::create(['product_id' => $locked->product_id, 'code' => $credentials['card_key'],
                    'code_hash' => hash_hmac('sha256', $credentials['card_key'], config('app.key')), 'batch_no' => 'remote-'.$candidate->id,
                    'instance_id' => $locked->id, 'status' => 'sold', 'sold_at' => now()]);
            }
            $task = FulfillmentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! $this->current($invoice, $locked, $task, $instance, $worker)) {
                throw new DomainException('Remote task changed while committing delivery.');
            }
            $before = $locked->status->value;
            $locked->update(['status' => InstanceStatus::ACTIVE, 'upstream_instance_id' => $stored['upstream_id'], 'encrypted_credentials' => $credentials]);
            $payload = $task->payload;
            $payload['remote_delivery']['state'] = 'delivered';
            $task->update(['payload' => $payload]);
            $this->audit($locked, 'instance.remote_card_provision', ['status' => InstanceStatus::ACTIVE->value,
                'previous_status' => $before, 'task_id' => $task->id, 'card_id' => $code->id, 'upstream_id' => $stored['upstream_id']]);

            return ProvisionResult::ok(upstreamInstanceId: $stored['upstream_id'], credentials: $credentials);
        });
    }

    private function current(Invoice $invoice, Instance $instance, FulfillmentTask $task, Instance $binding, string $worker): bool
    {
        $target = $task->payload['remote_delivery']['base_url'] ?? null;
        if ($target !== null && $instance->upstreamApi()->value('base_url') !== $target) {
            return false;
        }

        return $invoice->user_id === $instance->user_id && $task->user_id === $instance->user_id && $instance->user_id === $binding->user_id
            && $invoice->status === InvoiceStatus::PAID && $invoice->instance_id === $instance->id && $task->invoice_id === $invoice->id
            && $task->instance_id === $instance->id && $task->driver_type === $instance->driver_type && $task->upstream_api_id === $instance->upstream_api_id
            && $instance->driver_type === $binding->driver_type && $instance->upstream_api_id === $binding->upstream_api_id
            && in_array($instance->status, [InstanceStatus::PENDING, InstanceStatus::ACTIVE], true)
            && $task->status === FulfillmentTaskStatus::RUNNING && $task->locked_by === $worker
            && $task->lease_expires_at !== null && $task->lease_expires_at->isFuture();
    }

    private function audit(Instance $instance, string $action, array $after): void
    {
        DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => $action, 'subject_type' => Instance::class,
            'subject_id' => $instance->id, 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
