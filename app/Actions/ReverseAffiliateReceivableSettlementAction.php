<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Support\OperationsAudit;
use App\Support\ReceivableOperation;
use App\Support\ReceivableRevision;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReverseAffiliateReceivableSettlementAction
{
    public function execute(AffiliateReceivableSettlement $settlement, User $actor, array $input): AffiliateReceivableReversal
    {
        if (is_string($input['request_id'] ?? null)) {
            $input['request_id'] = Str::upper(trim($input['request_id']));
        }
        $data = validator($input, ['request_id' => ['required', 'ulid'], 'reason' => ['required', 'string', 'max:255'],
            'correction_reference' => ['required', 'string', 'max:128'], 'confirm' => ['required', 'accepted'],
            'version' => ['required', 'regex:/^[a-f0-9]{64}$/D']])->validate();
        $hint = AffiliateReceivableSettlement::findOrFail($settlement->id);
        $rootHint = FinancialTransaction::findOrFail($hint->clawback_transaction_id);
        $operation = app(ReceivableOperation::class);
        try {
            $write = fn (?array $permits) => DB::transaction(function () use ($hint, $rootHint, $actor, $data, $operation, $permits): AffiliateReceivableReversal {
                [$owner, $root] = $operation->lock($rootHint, $actor);
                $entry = AffiliateReceivableSettlement::whereKey($hint->id)->lockForUpdate()->firstOrFail();
                $balance = app(AffiliateReceivableBalance::class);
                $existing = AffiliateReceivableReversal::where('request_id', $data['request_id'])->first();
                if ($existing !== null) {
                    if ($existing->settlement_id !== $entry->id || $existing->user_id !== $owner->id || $existing->reason !== $data['reason']
                        || $existing->correction_reference !== $data['correction_reference']) {
                        $operation->conflict();
                    }

                    return $existing;
                }
                app(ReceivableAdmission::class)->assertLocked($owner, $permits[$owner->id] ?? []);
                $projection = $permits[$owner->id]['projections'][$root->id] ?? null;
                if ($projection === null) {
                    $operation->conflict();
                }
                $balance->assertValid($projection);
                if ($entry->user_id !== $owner->id || $entry->clawback_transaction_id !== $root->id || $entry->reversed_at !== null
                    || $entry->reversal()->exists() || AffiliateReceivableSettlement::where('request_id', $data['request_id'])->exists()) {
                    $operation->conflict();
                }
                $operation->version($projection, $data['version']);
                $ledger = FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $root->invoice_id,
                    'type' => TransactionType::AFFILIATE_RECEIVABLE_REVERSE, 'amount' => $entry->amount,
                    'balance_after' => $owner->credit, 'frozen_after' => $owner->frozen_credit, 'reference_key' => 'receivable_reverse:'.$data['request_id'],
                    'remark' => $data['reason'], 'meta' => ['currency' => 'CNY', 'clawback_transaction_id' => $root->id,
                        'settlement_id' => $entry->id, 'request_id' => $data['request_id'], 'no_wallet_movement' => true]]);
                $reverse = AffiliateReceivableReversal::create(['settlement_id' => $entry->id, 'user_id' => $owner->id,
                    'clawback_transaction_id' => $root->id, 'invoice_id' => $root->invoice_id, 'amount' => $entry->amount,
                    'currency' => 'CNY', 'request_id' => $data['request_id'], 'ledger_transaction_id' => $ledger->id,
                    'processed_by' => $actor->id, 'reason' => $data['reason'], 'correction_reference' => $data['correction_reference']]);
                $entry->update(['active_receipt_key' => null, 'reversed_at' => now()]);
                app(ReceivableRevision::class)->bump($owner);
                OperationsAudit::record($actor, 'affiliate.receivable_reversed', AffiliateReceivableReversal::class, $reverse->id, $data['reason'],
                    ['remaining' => $projection['remaining']], ['amount' => $entry->amount, 'clawback_transaction_id' => $root->id, 'settlement_id' => $entry->id]);
                app(NotificationManager::class)->queue($owner->id, 'affiliate.receivable.reversed', ['amount' => $entry->amount, 'currency' => 'CNY', 'status' => 'reversed'], eventKey: 'receivable-reversal:'.$reverse->id.':reversed');
                $attention = $owner->trashed() || $owner->anonymized_at !== null ? 'closed_account'
                    : (AffiliateWithdrawal::where('user_id', $owner->id)->where('destination', 'external')->whereIn('status', ['reserved', 'processing', 'unknown'])->exists() ? 'external_payout' : null);
                if ($attention !== null) {
                    OperationsAudit::record($actor, 'affiliate.receivable_attention', AffiliateReceivableReversal::class, $reverse->id,
                        'Reopened receivable requires reconciliation; existing identity and payout facts were retained', [],
                        ['status' => $attention, 'user_id' => $owner->id, 'clawback_transaction_id' => $root->id, 'settlement_id' => $entry->id, 'amount' => $entry->amount]);
                    app(NotificationManager::class)->queueOperations('affiliate.receivable.attention',
                        ['amount' => $entry->amount, 'currency' => 'CNY', 'status' => $attention], eventKey: 'receivable-reversal:'.$reverse->id.':attention');
                }

                return $reverse;
            });
            if (AffiliateReceivableReversal::where('request_id', $data['request_id'])->exists()) {
                return $write(null);
            }

            return app(ReceivableAdmission::class)->runMany([User::withTrashed()->findOrFail($rootHint->user_id)], 'receivable-reverse',
                $data['request_id'], ['settlement_id' => $hint->id, 'actor_id' => $actor->id, ...$data], $write, false, false,
                [$rootHint->user_id => [$rootHint->id]]);
        } catch (UniqueConstraintViolationException) {
            $operation->conflict();
        }
    }
}
