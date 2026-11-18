<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateCommission;
use App\Models\Invoice;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class AccrueAffiliateCommissionAction
{
    public const ZERO_ACCRUAL_ACTION = 'affiliate.zero_accrual';

    public function execute(int $invoiceId): ?AffiliateCommission
    {
        $hint = Invoice::findOrFail($invoiceId);
        $snapshot = $hint->line_items['affiliate'] ?? null;
        if (! is_array($snapshot)) {
            return null;
        }

        return DB::transaction(function () use ($hint, $snapshot): ?AffiliateCommission {
            $users = User::whereKey([$hint->user_id, $snapshot['referrer_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $invoice = Invoice::whereKey($hint->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== InvoiceStatus::PAID || $invoice->type !== InvoiceType::NEW_ORDER || $invoice->currency !== 'CNY'
                || $invoice->user_id === $snapshot['referrer_id'] || ! $users->has($invoice->user_id) || ! $users->has($snapshot['referrer_id'])
                || ($invoice->line_items['affiliate'] ?? null) !== $snapshot || $invoice->paid_at === null || Money::isZero($invoice->total_amount)) {
                return null;
            }
            $amount = Money::percentOf($invoice->total_amount, $snapshot['rate']);
            if (Money::isZero($amount)) {
                // Completion evidence is separate from the immutable invoice and money ledger.
                $audit = DB::table('audit_logs')->where('action', self::ZERO_ACCRUAL_ACTION)
                    ->where('subject_type', Invoice::class)->where('subject_id', $invoice->id);
                if (! $audit->exists()) {
                    DB::table('audit_logs')->insert([
                        'actor_type' => 'system', 'action' => self::ZERO_ACCRUAL_ACTION,
                        'subject_type' => Invoice::class, 'subject_id' => $invoice->id,
                        'reason' => 'The paid invoice commission rounds to zero; no commission is payable.',
                        'after' => json_encode(['invoice_amount' => $invoice->total_amount, 'rate' => $snapshot['rate'],
                            'amount' => $amount, 'referrer_id' => $snapshot['referrer_id']], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return null;
            }
            $commission = AffiliateCommission::firstOrCreate(['invoice_id' => $invoice->id], ['referrer_id' => $snapshot['referrer_id'],
                'buyer_id' => $invoice->user_id, 'rate_snapshot' => $snapshot['rate'], 'amount' => $amount, 'status' => 'pending',
                'available_at' => $invoice->paid_at->copy()->addDays($snapshot['freeze_days'])]);
            if ($commission->wasRecentlyCreated) {
                app(NotificationManager::class)->queue($commission->referrer_id, 'affiliate.pending', ['amount' => $commission->amount], eventKey: 'commission:'.$commission->id.':pending');
            }

            return $commission;
        });
    }
}
