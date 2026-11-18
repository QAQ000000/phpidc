<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Invoice;

class NotifyRenewalAction
{
    public function execute(Invoice $invoice): void
    {
        app(RecordNotificationEventAction::class)->execute($invoice->user_id, 'renewal.invoice',
            ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency],
            'invoice:'.$invoice->id.':renewal-created', $invoice->id);
    }
}
