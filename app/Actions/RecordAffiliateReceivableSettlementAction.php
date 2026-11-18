<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Services\NotificationManager;
use App\Support\Money;
use App\Support\OperationsAudit;
use App\Support\ReceivableOperation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordAffiliateReceivableSettlementAction
{
    public function execute(FinancialTransaction $clawback, User $actor, array $input): AffiliateReceivableSettlement
    {
        foreach (['request_id', 'external_channel', 'external_reference'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = $field === 'external_channel' ? Str::lower(trim($input[$field])) : Str::upper(trim($input[$field]));
            }
        }
        $data = validator($input, [
            'amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'], 'source' => ['required', 'in:external_received'],
            'request_id' => ['required', 'ulid'], 'external_channel' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,31}$/D'],
            'external_reference' => ['required', 'regex:/^[A-Z0-9][A-Z0-9._:\/-]{0,127}$/D'],
            'received_at' => ['required', 'date', 'before_or_equal:now'], 'reason' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'], 'version' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'corrects_settlement_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'in:CNY'],
        ])->validate();
        if (Money::isZero($data['amount'])) {
            throw ValidationException::withMessages(['amount' => '清偿金额必须大于 0。']);
        }
        $data['received_at'] = Carbon::parse($data['received_at'])->setTimezone(config('app.timezone'))->startOfSecond();
        $hint = FinancialTransaction::findOrFail($clawback->id);
        $correctionHint = isset($data['corrects_settlement_id']) ? AffiliateReceivableSettlement::findOrFail($data['corrects_settlement_id']) : null;
        $operation = app(ReceivableOperation::class);

        try {
            return DB::transaction(function () use ($hint, $actor, $data, $operation, $correctionHint): AffiliateReceivableSettlement {
                [$owner, $root] = $operation->lock($hint, $actor, $correctionHint);
                $balance = app(AffiliateReceivableBalance::class);
                $projection = $balance->project($root);
                $balance->assertValid($projection);
                $existing = AffiliateReceivableSettlement::where('request_id', $data['request_id'])->first();
                if ($existing !== null) {
                    if ($existing->clawback_transaction_id !== $root->id || $existing->user_id !== $owner->id
                        || ! Money::eq($existing->amount, $data['amount']) || $existing->source !== $data['source']
                        || $existing->external_channel !== $data['external_channel'] || $existing->external_reference !== $data['external_reference']
                        || $existing->received_at->ne($data['received_at']) || $existing->reason !== $data['reason']
                        || $existing->corrects_settlement_id !== (isset($data['corrects_settlement_id']) ? (int) $data['corrects_settlement_id'] : null)) {
                        $operation->conflict();
                    }

                    return $existing;
                }
                if (AffiliateReceivableReversal::where('request_id', $data['request_id'])->exists()) {
                    $operation->conflict();
                }
                $operation->version($projection, $data['version']);
                if (Money::gt($data['amount'], $projection['remaining']) || Money::isZero($projection['remaining'])) {
                    throw ValidationException::withMessages(['amount' => '清偿金额不能超过当前剩余应收。']);
                }
                $receipt = AffiliateReceivableBalance::receiptKey($data['external_channel'], $data['external_reference']);
                if (AffiliateReceivableSettlement::where('active_receipt_key', $receipt)->exists()) {
                    $operation->conflict();
                }
                $history = AffiliateReceivableSettlement::where('external_channel', $data['external_channel'])->where('external_reference', $data['external_reference'])->latest('id')->first();
                $corrects = isset($data['corrects_settlement_id']) ? AffiliateReceivableSettlement::whereKey($data['corrects_settlement_id'])->lockForUpdate()->firstOrFail() : null;
                if (($history !== null && $corrects?->id !== $history->id) || ($corrects !== null
                    && ($corrects->external_channel !== $data['external_channel'] || $corrects->external_reference !== $data['external_reference']
                        || $corrects->reversed_at === null || $corrects->active_receipt_key !== null || ! $corrects->reversal()->exists()
                        || AffiliateReceivableSettlement::where('corrects_settlement_id', $corrects->id)->exists()))) {
                    $operation->conflict();
                }
                if ($corrects !== null) {
                    if ($corrects->user_id !== $correctionHint->user_id || $corrects->invoice_id !== $correctionHint->invoice_id
                        || $corrects->clawback_transaction_id !== $correctionHint->clawback_transaction_id) {
                        $operation->conflict();
                    }
                    $balance->assertValid($balance->project(FinancialTransaction::findOrFail($corrects->clawback_transaction_id)));
                }
                $ledger = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $root->invoice_id,
                    'type' => TransactionType::AFFILIATE_RECEIVABLE_SETTLE, 'amount' => $data['amount'], 'balance_after' => $owner->credit,
                    'frozen_after' => $owner->frozen_credit, 'reference_key' => 'receivable_settle:'.$data['request_id'], 'remark' => $data['reason'],
                    'meta' => ['currency' => 'CNY', 'clawback_transaction_id' => $root->id, 'request_id' => $data['request_id'],
                        'source' => 'external_received', 'no_wallet_movement' => true]]);
                $entry = AffiliateReceivableSettlement::create(['user_id' => $owner->id, 'clawback_transaction_id' => $root->id,
                    'invoice_id' => $root->invoice_id, 'amount' => $data['amount'], 'currency' => 'CNY', 'source' => 'external_received',
                    'external_channel' => $data['external_channel'], 'external_reference' => $data['external_reference'],
                    'active_receipt_key' => $receipt, 'request_id' => $data['request_id'], 'ledger_transaction_id' => $ledger->id,
                    'corrects_settlement_id' => $corrects?->id, 'processed_by' => $actor->id, 'reason' => $data['reason'], 'received_at' => $data['received_at']]);
                OperationsAudit::record($actor, 'affiliate.receivable_settled', AffiliateReceivableSettlement::class, $entry->id, $data['reason'],
                    ['remaining' => $projection['remaining']], ['amount' => $entry->amount, 'remaining' => Money::sub($projection['remaining'], $entry->amount), 'clawback_transaction_id' => $root->id]);
                app(NotificationManager::class)->queue($owner->id, 'affiliate.receivable.settled', ['amount' => $entry->amount, 'currency' => 'CNY', 'status' => 'settled'], eventKey: 'receivable-receipt:'.$entry->id.':settled');

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            $operation->conflict();
        }
    }
}
