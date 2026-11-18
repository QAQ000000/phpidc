<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceEntitlementArchive;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use ValueError;

class OneTimeEntitlementEvidence
{
    public function __construct(private readonly DriverManager $drivers) {}

    /** @return array<string, mixed> Stable, non-secret payment and delivery facts. */
    public function snapshot(Instance $instance): array
    {
        try {
            return $this->collect($instance);
        } catch (DecryptException|JsonException|InvalidArgumentException|ValueError) {
            $this->reject();
        }
    }

    public function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($snapshot), JSON_THROW_ON_ERROR));
    }

    /** Evidence must still match; an archive is never a general lifecycle override. */
    public function validArchive(Instance $instance): bool
    {
        $current = Instance::find($instance->id);
        $archive = InstanceEntitlementArchive::where('instance_id', $instance->id)->first();
        if ($current === null || $archive === null || $archive->user_id !== $current->user_id
            || $archive->confirmation_version !== 1 || $archive->archived_at->lt($archive->confirmed_at)) {
            return false;
        }
        try {
            $raw = $archive->getRawOriginal('delivery_snapshot');
            if (! is_string($raw)) {
                return false;
            }
            $snapshot = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($snapshot)) {
                return false;
            }

            return hash_equals($archive->delivery_fingerprint, $this->fingerprint($snapshot))
                && hash_equals($archive->delivery_fingerprint, $this->fingerprint($this->snapshot($current)));
        } catch (ValidationException|JsonException) {
            // Missing/corrupt delivery evidence cannot authorize an account-closure exception.
            return false;
        }
    }

    private function collect(Instance $instance): array
    {
        $driver = $this->drivers->oneTimeDelivery($instance);
        if ($driver === null || $instance->currency !== 'CNY'
            || isset($instance->active_config['cancellation'])
            || in_array($instance->active_config['cloud_operation']['status'] ?? null, ['requested', 'unknown'], true)
            || InstanceUpstreamBinding::where('instance_id', $instance->id)->where(function ($query): void {
                $query->whereNull('unbound_at')->orWhere('upstream_disposition', 'kept_orphan');
            })->exists()) {
            $this->reject();
        }
        $delivery = $driver->deliveryEvidence($instance);
        $invoices = Invoice::where('instance_id', $instance->id)->orderBy('id')->get();
        $invoice = $invoices->first();
        if ($invoices->count() !== 1 || $invoice === null || $invoice->type !== InvoiceType::NEW_ORDER
            || $invoice->status !== InvoiceStatus::PAID || $invoice->paid_at === null || $invoice->user_id !== $instance->user_id
            || $invoice->product_id !== $instance->product_id || $invoice->currency !== $instance->currency
            || ($invoice->line_items['purchase']['driver_type'] ?? null) !== $instance->driver_type->value
            || ($invoice->line_items['purchase']['upstream_api_id'] ?? null) !== $instance->upstream_api_id
            || ($invoice->line_items['purchase']['billing_cycle'] ?? null) !== $instance->billing_cycle->value
            || ! Money::eq(Money::add($invoice->credit_applied, $invoice->gateway_amount), $invoice->total_amount)
            || PaymentAttempt::where('invoice_id', $invoice->id)->whereIn('status', ['unknown', 'reconciling'])->exists()) {
            $this->reject();
        }
        $ledger = $this->paymentEvidence($invoice);
        $tasks = FulfillmentTask::where('instance_id', $instance->id)->orderBy('id')->get();
        foreach ($tasks as $task) {
            if ($task->status->isOpen()
                || in_array($task->payload['operation']['state'] ?? null, ['create_requested', 'create_uncertain', 'invoice_created', 'payment_requested', 'paid', 'control_requested', 'control_uncertain', 'control_accepted'], true)
                || in_array($task->payload['remote_delivery']['state'] ?? null, ['requested', 'uncertain', 'received'], true)) {
                $this->reject();
            }
        }
        $provisions = $tasks->where('action', 'provision')->where('status', FulfillmentTaskStatus::SUCCESS);
        $task = $provisions->first();
        if ($provisions->count() !== 1 || $task === null || $task->executed_at === null || $task->invoice_id !== $invoice->id
            || $task->user_id !== $instance->user_id || $task->driver_type !== $instance->driver_type
            || $task->upstream_api_id !== $instance->upstream_api_id || $task->locked_by !== null || $task->lease_expires_at !== null) {
            $this->reject();
        }
        $remote = $instance->upstream_api_id === null ? null : $this->remoteEvidence($instance, $task);
        if ($remote === null && ($instance->upstream_instance_id !== null || isset($task->payload['remote_delivery']))) {
            $this->reject();
        }

        return ['version' => 1, 'instance' => ['id' => $instance->id, 'user_id' => $instance->user_id,
            'product_id' => $instance->product_id, 'driver_type' => $instance->driver_type->value,
            'upstream_api_id' => $instance->upstream_api_id, 'upstream_instance_id' => $instance->upstream_instance_id,
            'billing_cycle' => $instance->billing_cycle->value, 'currency' => $instance->currency],
            'delivery' => $delivery, 'invoice' => ['id' => $invoice->id, 'total_amount' => $invoice->total_amount,
                'credit_applied' => $invoice->credit_applied, 'gateway_amount' => $invoice->gateway_amount,
                'paid_at' => $invoice->paid_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')],
            'ledger' => $ledger, 'task' => ['id' => $task->id, 'executed_at' => $task->executed_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')],
            'remote_delivery' => $remote];
    }

    private function paymentEvidence(Invoice $invoice): array
    {
        $rows = FinancialTransaction::where('invoice_id', $invoice->id)->get();
        if ($rows->contains(fn (FinancialTransaction $row): bool => in_array($row->type, [TransactionType::CREDIT_UNFREEZE, TransactionType::REFUND_WALLET], true))) {
            $this->reject();
        }
        $expected = ['settle' => [TransactionType::CREDIT_SETTLE, $invoice->credit_applied]];
        if (! Money::isZero($invoice->credit_applied)) {
            $expected['freeze'] = [TransactionType::CREDIT_FREEZE, $invoice->credit_applied];
        }
        if (! Money::isZero($invoice->gateway_amount)) {
            $expected['payment'] = [TransactionType::PAYMENT, $invoice->gateway_amount];
        }
        $evidence = [];
        foreach ($expected as $key => [$type, $amount]) {
            $matches = $rows->where('type', $type);
            $entry = $matches->first();
            if ($matches->count() !== 1 || $entry === null || $entry->user_id !== $invoice->user_id
                || $entry->reference_key !== $key.':'.$invoice->id || ! Money::eq($entry->amount, $amount)) {
                $this->reject();
            }
            $evidence[$key] = $entry->id;
        }

        return $evidence;
    }

    private function remoteEvidence(Instance $instance, FulfillmentTask $task): array
    {
        $remote = $task->payload['remote_delivery'] ?? [];
        if (($remote['state'] ?? null) !== 'delivered' || ($remote['upstream_api_id'] ?? null) !== $instance->upstream_api_id
            || ! is_string($remote['idempotency_key'] ?? null) || ! str_starts_with($remote['idempotency_key'], 'cms-sub2api-')
            || ! is_string($remote['face_value'] ?? null) || ! is_string($remote['base_url'] ?? null)
            || ! is_string($remote['requested_at'] ?? null) || ! is_string($remote['encrypted_result'] ?? null)
            || ! is_string($task->payload['encrypted_purchase'] ?? null) || $instance->upstream_instance_id === null) {
            $this->reject();
        }
        $result = json_decode(Crypt::decryptString($remote['encrypted_result']), true, flags: JSON_THROW_ON_ERROR);
        $purchase = json_decode(Crypt::decryptString($task->payload['encrypted_purchase']), true, flags: JSON_THROW_ON_ERROR);
        $credentials = $instance->encrypted_credentials;
        if (! is_array($result) || ! is_array($purchase) || ! is_array($credentials) || ! is_array($result['credentials'] ?? null)
            || ($result['upstream_id'] ?? null) !== $instance->upstream_instance_id
            || Arr::sortRecursive($result['credentials']) !== Arr::sortRecursive($credentials)
            || ($credentials['face_value'] ?? null) !== $remote['face_value']
            || ($credentials['redeem_url'] ?? null) !== $remote['base_url'].'/redeem'
            || ($purchase['sub2api_face_value'] ?? null) !== $remote['face_value']
            || ($purchase['sub2api_base_url'] ?? null) !== $remote['base_url'] || ($purchase['invoice_id'] ?? null) !== $task->invoice_id
            || Money::isZero(Money::of($remote['face_value']))) {
            $this->reject();
        }

        return ['state' => 'delivered', 'idempotency_key' => $remote['idempotency_key'], 'face_value' => $remote['face_value'],
            'requested_at' => CarbonImmutable::parse($remote['requested_at'])->utc()->format('Y-m-d\TH:i:s\Z')];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['operation' => '该服务尚不满足完整付款、一次性交付及无未决业务的归档条件，请先完成核对。']);
    }
}
