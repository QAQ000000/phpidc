<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Support\AdminFinancialAccess;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;

class ReadOfflineInvoiceReceiptDetailsAction
{
    public function execute(OfflineInvoiceReceipt $receipt, User $actor): array
    {
        return DB::transaction(function () use ($receipt, $actor): array {
            $target = User::withTrashed()->findOrFail($receipt->user_id);
            ['actor' => $operator, 'target' => $owner] = app(AdminFinancialAccess::class)->lock($target, $actor, 'finance.offline-receipt');
            $locked = OfflineInvoiceReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->user_id === $owner->id, 409);
            $evidence = $locked->encrypted_evidence;
            OperationsAudit::record($operator, 'invoice.offline_receipt_details', OfflineInvoiceReceipt::class, $locked->id,
                '查看线下收款凭证', [], ['user_id' => $owner->id, 'invoice_id' => $locked->invoice_id]);

            return ['receipt_id' => $locked->id, 'external_channel' => $locked->external_channel,
                'external_reference' => $locked->external_reference, 'evidence' => $evidence ?? ''];
        });
    }
}
