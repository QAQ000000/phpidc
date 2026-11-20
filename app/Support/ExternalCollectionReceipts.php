<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TransactionType;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\ExternalCollectionReceipt;
use App\Models\FinancialTransaction;
use App\Models\OfflineInvoiceReceipt;
use App\Services\AffiliateReceivableBalance;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Shared incoming-cash uniqueness; outgoing transfers retain their separate registry.
 *
 * @phpstan-type CollectionClaim array{offline_invoice_receipt_id: int|null, receivable_settlement_id: int|null, user_id: int, registered_by: int, active_receipt_key: string|null, external_channel: string, external_reference: string, amount: string, currency: string, released_at: \Carbon\Carbon|null}
 */
class ExternalCollectionReceipts
{
    public function claimOffline(OfflineInvoiceReceipt $receipt): void
    {
        $this->transaction();
        // A reversed receivable is corrected in its original workflow, never reused to pay a bill.
        // A current read sees a different owner's claim/reversal committed after this
        // transaction's RR snapshot. Historical released rows remain authoritative.
        if (ExternalCollectionReceipt::where('external_channel', $receipt->external_channel)
            ->where('external_reference', $receipt->external_reference)->whereNotNull('receivable_settlement_id')
            ->lockForUpdate()->exists()) {
            $this->conflict();
        }
        $this->claim(['offline_invoice_receipt_id' => $receipt->id, 'receivable_settlement_id' => null,
            'user_id' => $receipt->user_id, 'registered_by' => $receipt->processed_by,
            'active_receipt_key' => AffiliateReceivableBalance::receiptKey($receipt->external_channel, $receipt->external_reference),
            'external_channel' => $receipt->external_channel, 'external_reference' => $receipt->external_reference,
            'amount' => $receipt->amount, 'currency' => $receipt->currency, 'released_at' => null]);
    }

    public function claimReceivable(AffiliateReceivableSettlement $entry): void
    {
        $this->transaction();
        if ($entry->reversed_at !== null || $entry->active_receipt_key !== AffiliateReceivableBalance::receiptKey($entry->external_channel, $entry->external_reference)) {
            $this->conflict();
        }
        $this->claim($this->receivableData($entry));
    }

    public function releaseReceivable(AffiliateReceivableSettlement $entry, AffiliateReceivableReversal $reversal): void
    {
        $this->transaction();
        $receipt = ExternalCollectionReceipt::where('receivable_settlement_id', $entry->id)->lockForUpdate()->first();
        $ledger = FinancialTransaction::find($reversal->ledger_transaction_id);
        if ($receipt === null || $entry->reversed_at === null || $entry->active_receipt_key !== null
            || $receipt->released_at !== null || $receipt->active_receipt_key !== AffiliateReceivableBalance::receiptKey($entry->external_channel, $entry->external_reference)
            || $receipt->user_id !== $entry->user_id || ! Money::eq($receipt->amount, $entry->amount)
            || $reversal->settlement_id !== $entry->id || $reversal->user_id !== $entry->user_id
            || $ledger === null || $ledger->type !== TransactionType::AFFILIATE_RECEIVABLE_REVERSE
            || $ledger->reference_key !== 'receivable_reverse:'.$reversal->request_id
            || $ledger->user_id !== $entry->user_id || $ledger->invoice_id !== $entry->invoice_id
            || ! Money::eq($ledger->amount, $entry->amount)) {
            $this->conflict();
        }
        $receipt->update(['active_receipt_key' => null, 'released_at' => $entry->reversed_at]);
    }

    /** Fail before DDL on conflicting or incomplete historical receipt ownership. */
    public function validateLegacy(): void
    {
        $duplicates = DB::table('affiliate_receivable_settlements')->whereNotNull('active_receipt_key')
            ->select('active_receipt_key')->groupBy('active_receipt_key')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            $this->conflict();
        }
        AffiliateReceivableSettlement::orderBy('id')->chunkById(100, function ($entries): void {
            foreach ($entries as $entry) {
                $data = $this->receivableData($entry);
                $ledger = FinancialTransaction::find($entry->ledger_transaction_id);
                if ($entry->currency !== 'CNY' || Money::isZero($entry->amount)
                    || ($entry->reversed_at === null && $entry->active_receipt_key !== $data['active_receipt_key'])
                    || ($entry->reversed_at !== null && ($entry->active_receipt_key !== null || ! $entry->reversal()->exists()))
                    || $ledger === null || $ledger->type !== TransactionType::AFFILIATE_RECEIVABLE_SETTLE
                    || $ledger->user_id !== $entry->user_id || $ledger->invoice_id !== $entry->invoice_id
                    || ! Money::eq($ledger->amount, $entry->amount) || $ledger->reference_key !== 'receivable_settle:'.$entry->request_id) {
                    $this->conflict();
                }
            }
        });
    }

    public function backfillLegacy(): void
    {
        DB::transaction(function (): void {
            AffiliateReceivableSettlement::orderBy('id')->chunkById(100, function ($entries): void {
                foreach ($entries as $entry) {
                    $this->claim($this->receivableData($entry));
                }
            });
        });
    }

    /** @return CollectionClaim */
    private function receivableData(AffiliateReceivableSettlement $entry): array
    {
        return ['offline_invoice_receipt_id' => null, 'receivable_settlement_id' => $entry->id,
            'user_id' => $entry->user_id, 'registered_by' => $entry->processed_by,
            'active_receipt_key' => $entry->reversed_at === null ? AffiliateReceivableBalance::receiptKey($entry->external_channel, $entry->external_reference) : null,
            'external_channel' => $entry->external_channel, 'external_reference' => $entry->external_reference,
            'amount' => $entry->amount, 'currency' => $entry->currency, 'released_at' => $entry->reversed_at];
    }

    /** @param CollectionClaim $data */
    private function claim(array $data): void
    {
        $this->transaction();
        $field = $data['offline_invoice_receipt_id'] === null ? 'receivable_settlement_id' : 'offline_invoice_receipt_id';
        $existing = ExternalCollectionReceipt::where($field, $data[$field])->first();
        if ($existing !== null) {
            foreach ($data as $key => $value) {
                $actual = $existing->getAttribute($key);
                if ($key === 'released_at' ? (($actual === null) !== ($value === null) || ($actual !== null && ! $actual->equalTo($value))) : $actual !== $value) {
                    $this->conflict();
                }
            }

            return;
        }
        try {
            // The unique active key arbitrates cross-owner races without missing-row gap locks.
            DB::transaction(fn () => ExternalCollectionReceipt::create([...$data, 'created_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            $this->conflict();
        }
    }

    private function transaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Collection claims require the original financial transaction.');
        }
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['external_reference' => '收款凭证已用于其他业务，或原凭证证据不一致，请核对原笔。']);
    }
}
