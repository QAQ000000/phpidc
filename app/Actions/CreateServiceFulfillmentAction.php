<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Services\NotificationManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

class CreateServiceFulfillmentAction
{
    public function execute(Invoice $invoice): void
    {
        if (DB::transactionLevel() === 0 || $invoice->status !== InvoiceStatus::PAID || ! in_array($invoice->type, [InvoiceType::RENEW, InvoiceType::UPGRADE], true)
            || $invoice->instance_id === null || ! isset($invoice->line_items['encrypted_operation'])) {
            throw new LogicException('Paid service fulfillment requires its settlement transaction and operation snapshot.');
        }
        $params = json_decode(Crypt::decryptString($invoice->line_items['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
        Instance::whereKey($invoice->instance_id)->lockForUpdate()->firstOrFail();
        $task = FulfillmentTask::create(['user_id' => $invoice->user_id, 'instance_id' => $invoice->instance_id, 'invoice_id' => $invoice->id,
            'driver_type' => $params['driver_type'], 'upstream_api_id' => $params['upstream_api_id'], 'action' => $invoice->type->value,
            'trigger_source' => 'order_paid', 'idempotency_key' => "{$invoice->id}:{$invoice->type->value}:{$invoice->instance_id}",
            'status' => 'pending', 'available_at' => now(), 'payload' => ['encrypted_operation' => $invoice->line_items['encrypted_operation']]]);
        DB::afterCommit(fn () => ExecuteFulfillmentTaskJob::dispatch($task->id));
        app(NotificationManager::class)->queue($invoice->user_id, 'invoice.paid', ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency]);
    }
}
