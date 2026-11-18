<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Exceptions\ReceivableScanBudgetExceeded;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Bounded root/evidence batches; all correctness decisions use the complete original evidence. */
class ReceivableEvidenceScanner
{
    public function scanUser(int $userId, int $seconds, array $captureIds = []): array
    {
        $deadline = hrtime(true) + max(0, $seconds) * 1000000000;
        $last = 0;
        $totals = ['roots' => 0, 'invalid' => 0, 'principal' => '0.00', 'settled' => '0.00', 'reversed' => '0.00', 'remaining' => '0.00'];
        $digest = hash_init('sha256');
        $outstanding = false;
        $captured = [];
        do {
            $this->budget($deadline);
            $roots = FinancialTransaction::where('user_id', $userId)->where('type', TransactionType::AFFILIATE_CLAWBACK)
                ->where('id', '>', $last)->orderBy('id')->limit((int) config('receivables.root_chunk_size', 100))->get();
            foreach ($this->project($roots, $deadline) as $row) {
                if (in_array($row['id'], $captureIds, true)) {
                    $captured[$row['id']] = $row;
                }
                $totals['roots']++;
                $totals['invalid'] += $row['valid'] ? 0 : 1;
                foreach (['principal', 'settled', 'reversed', 'remaining'] as $column) {
                    $totals[$column] = Money::add($totals[$column], $row[$column]);
                }
                $outstanding = $outstanding || ! $row['valid'] || ! Money::isZero($row['remaining']);
                hash_update($digest, $this->encoded([$row['id'], $row['version'], $row['valid'], $row['remaining']]));
            }
            if ($roots->isNotEmpty()) {
                $last = $roots->last()->id;
            }
        } while ($roots->isNotEmpty());
        $this->budget($deadline);

        return ['valid' => $totals['invalid'] === 0, 'has_outstanding' => $outstanding, 'digest' => hash_final($digest), 'totals' => $totals, 'projections' => $captured];
    }

    public function project(Collection $roots, ?int $deadline = null): Collection
    {
        if ($roots->isEmpty()) {
            return collect();
        }
        if ($roots->count() > (int) config('receivables.root_chunk_size', 100)) {
            return $roots->chunk((int) config('receivables.root_chunk_size', 100))
                ->reduce(fn (Collection $all, Collection $chunk): Collection => $all->union($this->project($chunk, $deadline)), collect());
        }
        $this->budget($deadline);
        $invoices = Invoice::whereIn('id', $roots->pluck('invoice_id')->filter())->get(['id', 'user_id', 'currency', 'status'])->keyBy('id');
        $commissions = AffiliateCommission::whereIn('invoice_id', $invoices->keys())->get()->keyBy('id');
        $states = [];
        foreach ($roots as $root) {
            $principal = $this->amount($root->meta['receivable_amount'] ?? null);
            $recovered = $this->amount($root->meta['recovered_amount'] ?? null);
            $invoice = $invoices->get($root->invoice_id);
            $commission = $commissions->get($root->meta['commission_id'] ?? null);
            $valid = $root->type === TransactionType::AFFILIATE_CLAWBACK && $principal !== null;
            if ($principal !== null && ! Money::isZero($principal)) {
                $valid = $valid && $recovered !== null && $invoice !== null && $commission !== null
                    && $root->reference_key === 'clawback:'.$commission->id && $commission->status === 'clawed_back'
                    && $commission->referrer_id === $root->user_id && $commission->buyer_id === $invoice->user_id
                    && $commission->invoice_id === $invoice->id && $invoice->status === InvoiceStatus::REFUNDED
                    && $invoice->currency === 'CNY' && ($root->meta['currency'] ?? null) === 'CNY'
                    && ($root->meta['previous_status'] ?? null) === 'available'
                    && Money::eq(Money::add($principal, $recovered), $root->amount) && Money::eq($root->amount, $commission->amount);
            }
            $hash = hash_init('sha256');
            hash_update($hash, $this->encoded([array_replace(['invoice_id' => null, 'balance_after' => null, 'frozen_after' => null, 'remark' => null, 'meta' => null], $root->attributesToArray()), $invoice?->attributesToArray(), $commission?->attributesToArray()]));
            $states[$root->id] = ['root' => $root, 'principal' => $principal ?? '0.00', 'settled' => '0.00', 'reversed' => '0.00',
                'valid' => $valid, 'reversals' => 0, 'hash' => $hash];
        }
        $last = 0;
        do {
            $this->budget($deadline);
            $settlements = AffiliateReceivableSettlement::whereIn('clawback_transaction_id', $roots->pluck('id')->all())->where('id', '>', $last)
                ->orderBy('id')->limit((int) config('receivables.evidence_chunk_size', 500))->get();
            $reversals = AffiliateReceivableReversal::whereIn('settlement_id', $settlements->modelKeys())->get()->keyBy('settlement_id');
            $ledgers = FinancialTransaction::whereIn('id', $settlements->pluck('ledger_transaction_id')->merge($reversals->pluck('ledger_transaction_id')))->get()->keyBy('id');
            foreach ($settlements as $entry) {
                $state = &$states[$entry->clawback_transaction_id];
                $root = $state['root'];
                $ledger = $ledgers->get($entry->ledger_transaction_id);
                $amount = $this->amount($entry->amount);
                $state['valid'] = $state['valid'] && $amount !== null && ! Money::isZero($amount)
                    && $entry->user_id === $root->user_id && $entry->invoice_id === $root->invoice_id
                    && $entry->currency === 'CNY' && $entry->source === 'external_received'
                    && $this->ledgerMatches($ledger, $entry, TransactionType::AFFILIATE_RECEIVABLE_SETTLE, 'receivable_settle:'.$entry->request_id);
                $state['settled'] = Money::add($state['settled'], $amount ?? '0.00');
                $reverse = $reversals->get($entry->id);
                if ($reverse !== null) {
                    $reverseAmount = $this->amount($reverse->amount);
                    $state['valid'] = $state['valid'] && $reverseAmount !== null && $amount !== null
                        && $reverse->user_id === $entry->user_id && $reverse->invoice_id === $entry->invoice_id
                        && $reverse->clawback_transaction_id === $root->id && $reverse->currency === $entry->currency
                        && Money::eq($reverseAmount, $amount) && $entry->reversed_at !== null && $entry->active_receipt_key === null
                        && $this->ledgerMatches($ledgers->get($reverse->ledger_transaction_id), $reverse, TransactionType::AFFILIATE_RECEIVABLE_REVERSE, 'receivable_reverse:'.$reverse->request_id);
                    $state['reversed'] = Money::add($state['reversed'], $reverseAmount ?? '0.00');
                    $state['reversals']++;
                } else {
                    $state['valid'] = $state['valid'] && $entry->reversed_at === null
                        && $entry->active_receipt_key === AffiliateReceivableBalance::receiptKey($entry->external_channel, $entry->external_reference);
                }
                hash_update($state['hash'], $this->encoded([$entry->attributesToArray(), $ledger?->attributesToArray(), $reverse?->attributesToArray(),
                    $reverse === null ? null : $ledgers->get($reverse->ledger_transaction_id)?->attributesToArray()]));
                unset($state);
            }
            if ($settlements->isNotEmpty()) {
                $last = $settlements->last()->id;
            }
        } while ($settlements->isNotEmpty());
        // A reversal pointing at the wrong root cannot disappear from a batch's evidence.
        $reverseCounts = AffiliateReceivableReversal::whereIn('clawback_transaction_id', $roots->pluck('id')->all())
            ->select('clawback_transaction_id')->selectRaw('COUNT(*) AS evidence_count')->groupBy('clawback_transaction_id')->get()->keyBy('clawback_transaction_id');
        $result = [];
        foreach ($states as $id => $state) {
            $remaining = $state['principal'];
            if (Money::gt($state['reversed'], $state['settled'])
                || Money::gt(Money::subFloorZero($state['settled'], $state['reversed']), $remaining)
                || $state['reversals'] !== (int) ($reverseCounts->get($id)?->getAttribute('evidence_count') ?? 0)) {
                $state['valid'] = false;
            } else {
                $remaining = Money::sub($remaining, Money::sub($state['settled'], $state['reversed']));
            }
            $result[$id] = ['id' => $id, 'user_id' => $state['root']->user_id, 'invoice_id' => $state['root']->invoice_id,
                'principal' => $state['principal'], 'settled' => $state['settled'], 'reversed' => $state['reversed'], 'remaining' => $remaining,
                'valid' => $state['valid'], 'error' => $state['valid'] ? null : '应收证据不完整或不一致，请人工核对。',
                'version' => hash_final($state['hash'])];
        }
        $this->budget($deadline);

        return collect($result);
    }

    private function amount(mixed $amount): ?string
    {
        return is_string($amount) && preg_match('/^\d{1,10}\.\d{2}$/D', $amount) === 1 ? $amount : null;
    }

    private function ledgerMatches(?FinancialTransaction $ledger, AffiliateReceivableSettlement|AffiliateReceivableReversal $entry, TransactionType $type, string $key): bool
    {
        return $ledger !== null && $this->amount($ledger->amount) !== null && $this->amount($entry->amount) !== null
            && $ledger->type === $type && $ledger->reference_key === $key && $ledger->user_id === $entry->user_id
            && $ledger->invoice_id === $entry->invoice_id && Money::eq($ledger->amount, $entry->amount)
            && ($ledger->meta['clawback_transaction_id'] ?? null) === $entry->clawback_transaction_id
            && ($ledger->meta['currency'] ?? null) === 'CNY' && ($ledger->meta['no_wallet_movement'] ?? false) === true;
    }

    private function encoded(array $facts): string
    {
        return json_encode(Arr::sortRecursive($facts), JSON_THROW_ON_ERROR)."\n";
    }

    private function budget(?int $deadline): void
    {
        if ($deadline !== null && hrtime(true) >= $deadline) {
            throw new ReceivableScanBudgetExceeded('Complete receivable verification exceeded its time budget.');
        }
    }
}
