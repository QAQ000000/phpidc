<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Support\SettlementCurrency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class FindPurchaseRequestAction
{
    /** The locked read is used only after locking the user in the creation transaction (INV-L1). */
    public function execute(int $userId, string $requestId, string $snapshot, bool $lock = false): ?Invoice
    {
        $query = Invoice::where('user_id', $userId)->where('purchase_request_id', $requestId);
        $invoice = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($invoice === null) {
            return null;
        }
        SettlementCurrency::assertSupported($invoice->currency);
        $saved = $invoice->line_items['purchase']['encrypted_request'] ?? null;
        if ($invoice->type !== InvoiceType::NEW_ORDER || ! is_string($saved)
            || ! hash_equals(hash('sha256', Crypt::decryptString($saved)), hash('sha256', $snapshot))) {
            throw ValidationException::withMessages(['request_id' => 'This purchase request already belongs to different parameters. Start a new purchase to change them.']);
        }

        return $invoice;
    }
}
