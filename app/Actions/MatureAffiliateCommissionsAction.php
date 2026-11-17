<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

class MatureAffiliateCommissionsAction
{
    public function execute(int $id): void
    {
        $hint = AffiliateCommission::findOrFail($id);
        DB::transaction(function () use ($hint): void {
            $user = User::whereKey($hint->referrer_id)->lockForUpdate()->firstOrFail();
            $invoice = Invoice::whereKey($hint->invoice_id)->lockForUpdate()->firstOrFail();
            $commission = AffiliateCommission::whereKey($hint->id)->lockForUpdate()->firstOrFail();
            if ($commission->status !== 'pending' || $commission->available_at === null || $commission->available_at->isFuture()
                || $invoice->status !== InvoiceStatus::PAID || $invoice->currency !== 'CNY' || $commission->referrer_id !== $user->id) {
                return;
            }
            $balance = Money::add($user->affiliate_balance, $commission->amount);
            if (Money::gt($balance, '9999999999.99')) {
                throw new DomainException('推广余额超过字段上限，需人工核查。');
            }
            $user->forceFill(['affiliate_balance' => $balance])->save();
            FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::AFFILIATE_COMMISSION,
                'amount' => $commission->amount, 'balance_after' => $user->credit, 'frozen_after' => $user->frozen_credit,
                'reference_key' => 'affiliate:'.$commission->id, 'meta' => ['currency' => 'CNY', 'affiliate_balance_after' => $balance]]);
            $commission->update(['status' => 'available']);
            app(NotificationManager::class)->queue($user->id, 'affiliate.available', ['amount' => $commission->amount]);
        });
    }
}
