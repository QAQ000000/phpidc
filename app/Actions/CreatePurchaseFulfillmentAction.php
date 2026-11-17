<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\AccrueAffiliateCommissionJob;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\NotificationManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreatePurchaseFulfillmentAction
{
    // Called only by the wallet or callback settlement transaction (INV-P2).
    public function execute(Invoice $invoice): void
    {
        if (DB::transactionLevel() === 0 || $invoice->status !== InvoiceStatus::PAID || $invoice->instance_id !== null) {
            throw new LogicException('New-purchase fulfillment must be created in its Paid settlement transaction.');
        }
        $product = $invoice->product()->firstOrFail();
        $purchase = $invoice->line_items['purchase'] ?? [];
        $params = isset($purchase['encrypted_params']) ? json_decode(Crypt::decryptString($purchase['encrypted_params']), true, flags: JSON_THROW_ON_ERROR) : [];
        $instance = Instance::create([
            'user_id' => $invoice->user_id, 'product_id' => $product->id,
            'driver_type' => $purchase['driver_type'] ?? $product->driver_type,
            'upstream_api_id' => $purchase['upstream_api_id'] ?? $product->upstream_api_id,
            'display_name' => $purchase['name'] ?? $product->name, 'hostname' => $purchase['hostname'] ?? null,
            'status' => InstanceStatus::PENDING, 'billing_cycle' => $purchase['billing_cycle'] ?? BillingCycle::ONETIME,
            'currency' => $invoice->currency, 'recurring_amount' => $purchase['recurring_amount'] ?? $invoice->total_amount,
            'active_config' => ['billing_cycle' => $purchase['billing_cycle'] ?? BillingCycle::ONETIME->value, 'currency' => $invoice->currency,
                'options' => $params['options']['pairs'] ?? [], 'upstream_configoption' => $purchase['options']['configoption'] ?? [], 'upstream_pid' => $purchase['upstream_pid'] ?? null],
            'is_price_locked' => $product->price_locked,
        ]);
        $invoice->update(['instance_id' => $instance->id]);
        $task = FulfillmentTask::create([
            'user_id' => $invoice->user_id, 'invoice_id' => $invoice->id, 'instance_id' => $instance->id,
            'driver_type' => $instance->driver_type, 'upstream_api_id' => $instance->upstream_api_id,
            'action' => 'provision', 'trigger_source' => 'order_paid', 'status' => FulfillmentTaskStatus::PENDING,
            'idempotency_key' => "{$invoice->id}:provision:{$instance->id}", 'payload' => isset($purchase['encrypted_params']) ? ['encrypted_purchase' => Crypt::encryptString(json_encode(json_decode(Crypt::decryptString($purchase['encrypted_params']), true, flags: JSON_THROW_ON_ERROR) + ['invoice_id' => $invoice->id], JSON_THROW_ON_ERROR))] : [], 'available_at' => now(),
        ]);
        DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($task->id));
        AccrueAffiliateCommissionJob::dispatch($invoice->id)->afterCommit();
        app(NotificationManager::class)->queue($invoice->user_id, 'invoice.paid', ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency]);
    }
}
