<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Models\WalletClearance;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BackfillExternalPayoutReceiptsAction
{
    /** @return array{dry_run: bool, scanned: int, registered: int, existing: int, would_register: int, conflicts: list<array{kind: string, id: int, reason: string, receipt_fingerprint: string}>} */
    public function execute(bool $dryRun = false): array
    {
        $report = ['dry_run' => $dryRun, 'scanned' => 0, 'registered' => 0, 'existing' => 0, 'would_register' => 0, 'conflicts' => []];
        $queries = ['affiliate' => AffiliateWithdrawal::where('destination', 'external')->where('status', 'paid'),
            'wallet' => WalletClearance::where('status', 'paid')];
        foreach ($queries as $kind => $query) {
            $query->select(['id', 'user_id', 'confirmed_by', 'payout_method', 'external_reference'])->chunkById(100, function ($sources) use ($kind, $dryRun, &$report): void {
                foreach ($sources as $hint) {
                    $report['scanned']++;
                    try {
                        $result = DB::transaction(function () use ($kind, $hint, $dryRun): string {
                            $users = User::withTrashed()->whereKey(array_filter([$hint->user_id, $hint->confirmed_by]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                            $actor = $users->get($hint->confirmed_by);
                            $source = $kind === 'affiliate' ? AffiliateWithdrawal::whereKey($hint->id)->lockForUpdate()->first()
                                : WalletClearance::whereKey($hint->id)->lockForUpdate()->first();
                            if ($source === null || $source->status !== 'paid' || $source->user_id !== $hint->user_id
                                || $source->confirmed_by !== $hint->confirmed_by || $actor === null || ! $users->has($source->user_id)
                                || ! is_string($source->payout_method) || ! is_string($source->external_reference)) {
                                throw ValidationException::withMessages(['external_reference' => '历史已付款项缺少可核对的业务主体、确认人或凭证。']);
                            }
                            $claim = app(ClaimExternalPayoutReceiptAction::class);
                            $currency = $source instanceof WalletClearance ? $source->currency : 'CNY';
                            $affiliateId = $kind === 'affiliate' ? $source->id : null;
                            $walletId = $kind === 'wallet' ? $source->id : null;
                            if ($claim->inspect($source->payout_method, $source->external_reference, $source->amount, $currency, $actor, $affiliateId, $walletId)) {
                                return 'existing';
                            }
                            if ($dryRun) {
                                return 'would_register';
                            }
                            $claim->execute($source->payout_method, $source->external_reference, $source->amount, $currency, $actor, $affiliateId, $walletId);

                            return 'registered';
                        });
                        $report[$result]++;
                    } catch (ValidationException $error) {
                        $report['conflicts'][] = ['kind' => $kind, 'id' => $hint->id, 'reason' => implode(' ', Arr::flatten($error->errors())),
                            'receipt_fingerprint' => hash_hmac('sha256', ($hint->payout_method ?? '').'|'.Str::upper(trim($hint->external_reference ?? '')), (string) config('app.key'))];
                    }
                }
            });
        }

        return $report;
    }
}
