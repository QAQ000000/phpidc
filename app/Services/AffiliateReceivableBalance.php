<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** @phpstan-type ReceivableProjection array{id: int, user_id: int, invoice_id: int|null, principal: string, settled: string, reversed: string, remaining: string, valid: bool, error: string|null, version: string} */
class AffiliateReceivableBalance
{
    /** @return Collection<int, ReceivableProjection> */
    public function forUser(int $userId): Collection
    {
        return $this->many(FinancialTransaction::where('user_id', $userId)->where('type', TransactionType::AFFILIATE_CLAWBACK)->orderBy('id')->get());
    }

    public function hasOutstanding(int $userId): bool
    {
        return $this->forUser($userId)->contains(fn (array $row): bool => ! $row['valid'] || Money::gt($row['remaining'], '0.00'));
    }

    /** @return ReceivableProjection */
    public function project(FinancialTransaction $root): array
    {
        return $this->many(collect([$root]))->get($root->id);
    }

    public function assertValid(array $projection): void
    {
        if (! $projection['valid']) {
            throw ValidationException::withMessages(['operation' => '应收证据不完整或不一致，请人工核对。']);
        }
    }

    /** @param Collection<int, FinancialTransaction> $roots
     * @return Collection<int, ReceivableProjection>
     */
    public function many(Collection $roots): Collection
    {
        $settlements = AffiliateReceivableSettlement::whereIn('clawback_transaction_id', $roots->pluck('id'))->orderBy('id')->get();
        $reversals = AffiliateReceivableReversal::whereIn('clawback_transaction_id', $roots->pluck('id'))->orderBy('id')->get();
        $ledgers = FinancialTransaction::whereIn('id', $settlements->pluck('ledger_transaction_id')->merge($reversals->pluck('ledger_transaction_id')))->get()->keyBy('id');
        $invoices = Invoice::whereIn('id', $roots->pluck('invoice_id')->filter())->get(['id', 'user_id', 'currency', 'status'])->keyBy('id');
        $commissions = AffiliateCommission::whereIn('invoice_id', $invoices->keys())->get()->keyBy('id');
        $byRoot = $settlements->groupBy('clawback_transaction_id');
        $reverseBySettlement = $reversals->keyBy('settlement_id');

        return $roots->mapWithKeys(function (FinancialTransaction $root) use ($byRoot, $reverseBySettlement, $ledgers, $invoices, $commissions): array {
            $principal = $this->amount($root->meta['receivable_amount'] ?? null);
            $recovered = $this->amount($root->meta['recovered_amount'] ?? null);
            $invoice = $invoices->get($root->invoice_id);
            $commission = $commissions->get($root->meta['commission_id'] ?? null);
            $valid = $root->type === TransactionType::AFFILIATE_CLAWBACK && $principal !== null;
            // Zero cancellation creates no debt; positive debt must retain its complete original C11 evidence.
            if ($principal !== null && ! Money::isZero($principal)) {
                $valid = $valid && $recovered !== null && $invoice !== null && $commission !== null
                    && $root->reference_key === 'clawback:'.$commission->id && $commission->status === 'clawed_back'
                    && $commission->referrer_id === $root->user_id && $commission->buyer_id === $invoice->user_id
                    && $commission->invoice_id === $invoice->id && $invoice->status === InvoiceStatus::REFUNDED
                    && $invoice->currency === 'CNY' && ($root->meta['currency'] ?? null) === 'CNY'
                    && ($root->meta['previous_status'] ?? null) === 'available'
                    && Money::eq(Money::add($principal, $recovered), $root->amount) && Money::eq($root->amount, $commission->amount);
            }
            $settled = $reversed = '0.00';
            $evidence = [];
            foreach ($byRoot->get($root->id, collect()) as $entry) {
                $ledger = $ledgers->get($entry->ledger_transaction_id);
                $valid = $valid && $entry->user_id === $root->user_id && $entry->invoice_id === $root->invoice_id
                    && $entry->currency === 'CNY' && $entry->source === 'external_received' && ! Money::isZero($entry->amount)
                    && $this->ledgerMatches($ledger, $entry, 'affiliate_receivable_settle', 'receivable_settle:'.$entry->request_id);
                $settled = Money::add($settled, $entry->amount);
                $reverse = $reverseBySettlement->get($entry->id);
                if ($reverse !== null) {
                    $valid = $valid && $reverse->user_id === $entry->user_id && $reverse->invoice_id === $entry->invoice_id
                        && $reverse->clawback_transaction_id === $root->id && $reverse->currency === $entry->currency
                        && Money::eq($reverse->amount, $entry->amount) && $entry->reversed_at !== null && $entry->active_receipt_key === null
                        && $this->ledgerMatches($ledgers->get($reverse->ledger_transaction_id), $reverse, 'affiliate_receivable_reverse', 'receivable_reverse:'.$reverse->request_id);
                    $reversed = Money::add($reversed, $reverse->amount);
                } else {
                    $valid = $valid && $entry->reversed_at === null
                        && $entry->active_receipt_key === self::receiptKey($entry->external_channel, $entry->external_reference);
                }
                $evidence[] = [$entry->id, $entry->amount, $entry->ledger_transaction_id, $entry->active_receipt_key, $reverse?->id, $reverse?->ledger_transaction_id];
            }
            $remaining = $principal ?? '0.00';
            if (Money::gt($reversed, $settled) || Money::gt(Money::subFloorZero($settled, $reversed), $remaining)) {
                $valid = false;
            } else {
                $remaining = Money::sub($remaining, Money::sub($settled, $reversed));
            }

            return [$root->id => ['id' => $root->id, 'user_id' => $root->user_id, 'invoice_id' => $root->invoice_id,
                'principal' => $principal ?? '0.00', 'settled' => $settled, 'reversed' => $reversed, 'remaining' => $remaining,
                'valid' => $valid, 'error' => $valid ? null : '应收证据不完整或不一致，请人工核对。',
                'version' => hash('sha256', json_encode([$root->id, $root->meta, $root->amount, $evidence], JSON_THROW_ON_ERROR))]];
        });
    }

    public static function receiptKey(string $channel, string $reference): string
    {
        return hash('sha256', strtolower(trim($channel))."\0".strtoupper(trim($reference)));
    }

    private function amount(mixed $amount): ?string
    {
        return is_string($amount) && preg_match('/^\d{1,10}\.\d{2}$/D', $amount) === 1 ? $amount : null;
    }

    private function ledgerMatches(?FinancialTransaction $ledger, AffiliateReceivableSettlement|AffiliateReceivableReversal $entry, string $type, string $key): bool
    {
        return $ledger !== null && $ledger->type->value === $type && $ledger->reference_key === $key
            && $ledger->user_id === $entry->user_id && $ledger->invoice_id === $entry->invoice_id && Money::eq($ledger->amount, $entry->amount)
            && ($ledger->meta['clawback_transaction_id'] ?? null) === $entry->clawback_transaction_id
            && ($ledger->meta['currency'] ?? null) === 'CNY' && ($ledger->meta['no_wallet_movement'] ?? false) === true;
    }
}
