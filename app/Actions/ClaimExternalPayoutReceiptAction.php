<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateWithdrawal;
use App\Models\ExternalPayoutReceipt;
use App\Models\User;
use App\Models\WalletClearance;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ClaimExternalPayoutReceiptAction
{
    /** The caller holds participant-user and payout locks; the registry is the next lock group. */
    public function execute(string $payoutMethod, string $externalReference, string $amount, string $currency, User $confirmedBy, ?int $affiliateWithdrawalId = null, ?int $walletClearanceId = null): void
    {
        $data = $this->prepare($payoutMethod, $externalReference, $amount, $currency, $confirmedBy, $affiliateWithdrawalId, $walletClearanceId);
        if ($this->registered($data)) {
            return;
        }
        try {
            // Contain the uniqueness failure in a savepoint, without poisoning the caller's transaction.
            DB::transaction(fn () => ExternalPayoutReceipt::create([...$data, 'created_at' => now()]));
        } catch (UniqueConstraintViolationException) {
            $this->reject();
        }
    }

    /** Read-only inspection for historical backfill dry runs; never substitutes for execute on payment. */
    public function inspect(string $payoutMethod, string $externalReference, string $amount, string $currency, User $confirmedBy, ?int $affiliateWithdrawalId = null, ?int $walletClearanceId = null): bool
    {
        return $this->registered($this->prepare($payoutMethod, $externalReference, $amount, $currency, $confirmedBy, $affiliateWithdrawalId, $walletClearanceId));
    }

    /** @return array{payout_method: string, external_reference: string, amount: string, currency: string, confirmed_by: int, affiliate_withdrawal_id: ?int, wallet_clearance_id: ?int} */
    private function prepare(string $payoutMethod, string $externalReference, string $amount, string $currency, User $confirmedBy, ?int $affiliateWithdrawalId, ?int $walletClearanceId): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Receipt claims require the locked payout transaction.');
        }
        $externalReference = Str::upper(trim($externalReference));
        if (($affiliateWithdrawalId === null) === ($walletClearanceId === null)
            || ($affiliateWithdrawalId !== null && $affiliateWithdrawalId < 1) || ($walletClearanceId !== null && $walletClearanceId < 1)
            || ! in_array($payoutMethod, ['bank_transfer', 'alipay'], true) || $externalReference === '' || mb_strlen($externalReference) > 128
            || preg_match('/^\d{1,10}\.\d{2}$/D', $amount) !== 1 || Money::isZero($amount) || $currency !== 'CNY'
            || ! $confirmedBy->exists || ! User::withTrashed()->whereKey($confirmedBy->id)->exists()) {
            $this->reject();
        }
        $source = $affiliateWithdrawalId === null ? WalletClearance::find($walletClearanceId) : AffiliateWithdrawal::find($affiliateWithdrawalId);
        if ($source === null || ! in_array($source->status, ['processing', 'unknown', 'paid'], true)
            || $source->payout_method !== $payoutMethod || ! Money::eq($source->amount, $amount)
            || ($source instanceof AffiliateWithdrawal && $source->destination !== 'external')
            || ($source instanceof WalletClearance && $source->currency !== $currency)
            || ($source->status === 'paid' && ($source->confirmed_by !== $confirmedBy->id
                || Str::upper(trim($source->external_reference ?? '')) !== $externalReference))) {
            $this->reject();
        }

        return ['payout_method' => $payoutMethod, 'external_reference' => $externalReference, 'amount' => $amount, 'currency' => $currency,
            'confirmed_by' => $confirmedBy->id, 'affiliate_withdrawal_id' => $affiliateWithdrawalId, 'wallet_clearance_id' => $walletClearanceId];
    }

    /** @param array{payout_method: string, external_reference: string, amount: string, currency: string, confirmed_by: int, affiliate_withdrawal_id: ?int, wallet_clearance_id: ?int} $data */
    private function registered(array $data): bool
    {
        $field = $data['affiliate_withdrawal_id'] === null ? 'wallet_clearance_id' : 'affiliate_withdrawal_id';
        // Existing records are immutable; missing-key reads must not take conflicting MySQL gap locks.
        // The caller serializes the business owner, and the insert's unique indexes arbitrate other owners.
        $existing = ExternalPayoutReceipt::where($field, $data[$field])->first();
        if ($existing !== null) {
            if ($existing->payout_method !== $data['payout_method'] || $existing->external_reference !== $data['external_reference']
                || ! Money::eq($existing->amount, $data['amount']) || $existing->currency !== $data['currency']
                || $existing->confirmed_by !== $data['confirmed_by']) {
                $this->reject();
            }
        } elseif (ExternalPayoutReceipt::where('payout_method', $data['payout_method'])->where('external_reference', $data['external_reference'])->exists()) {
            $this->reject();
        }
        // Historical paid facts remain authoritative before their registry backfill finishes.
        foreach (['affiliate_withdrawals' => 'affiliate_withdrawal_id', 'wallet_clearances' => 'wallet_clearance_id'] as $table => $ownerField) {
            $query = DB::table($table)->where('status', 'paid')->where('payout_method', $data['payout_method'])
                ->whereRaw('UPPER(TRIM(external_reference)) = ?', [$data['external_reference']]);
            if ($table === 'affiliate_withdrawals') {
                $query->where('destination', 'external');
            }
            if ($data[$ownerField] !== null) {
                $query->where('id', '!=', $data[$ownerField]);
            }
            if ($query->exists()) {
                $this->reject();
            }
        }

        return $existing !== null;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['external_reference' => '外部凭证、金额或业务归属不一致，或已用于另一笔已付款项，请核对原笔。']);
    }
}
