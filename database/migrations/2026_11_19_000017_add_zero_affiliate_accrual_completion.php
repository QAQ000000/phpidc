<?php

declare(strict_types=1);

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits separately: a failed historical conversion must be retryable.
        if (! Schema::hasColumn('invoices', 'affiliate_zero_accrual_at')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->timestamp('affiliate_zero_accrual_at')->nullable());
        }

        DB::table('invoices')->whereNull('affiliate_zero_accrual_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('audit_logs')->whereColumn('audit_logs.subject_id', 'invoices.id')
                ->where('audit_logs.subject_type', 'App\\Models\\Invoice')->where('audit_logs.action', 'affiliate.zero_accrual'))
            ->orderBy('id')->chunkById(100, function ($invoices): void {
                foreach ($invoices as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $invoice = DB::table('invoices')->where('id', $candidate->id)->lockForUpdate()->first();
                        if ($invoice === null || $invoice->affiliate_zero_accrual_at !== null) {
                            return;
                        }
                        $completedAt = $this->completedAt($invoice);
                        if ($completedAt !== null) {
                            // Preserve historical updated_at, quote, balances and all original evidence.
                            DB::table('invoices')->where('id', $invoice->id)->whereNull('affiliate_zero_accrual_at')
                                ->update(['affiliate_zero_accrual_at' => $completedAt]);
                        }
                    });
                }
            });
    }

    private function completedAt(object $invoice): ?string
    {
        if ($invoice->status !== 'Paid' || $invoice->type !== 'new_order' || $invoice->currency !== 'CNY' || $invoice->paid_at === null
            || DB::table('affiliate_commissions')->where('invoice_id', $invoice->id)->exists()) {
            return null;
        }
        try {
            $items = json_decode($invoice->line_items ?? '', true, flags: JSON_THROW_ON_ERROR);
            $snapshot = is_array($items) ? ($items['affiliate'] ?? null) : null;
            if (! is_array($snapshot) || ! is_int($snapshot['referrer_id'] ?? null) || $snapshot['referrer_id'] <= 0
                || (int) $invoice->user_id === $snapshot['referrer_id'] || ! is_string($snapshot['rate'] ?? null)
                || preg_match('/^\d+\.\d{2}$/', $snapshot['rate']) !== 1 || Money::gt($snapshot['rate'], '100.00')
                || ! is_int($snapshot['freeze_days'] ?? null) || $snapshot['freeze_days'] < 0
                || DB::table('users')->whereIn('id', [$invoice->user_id, $snapshot['referrer_id']])->count() !== 2) {
                return null;
            }
            $invoiceAmount = Money::of((string) $invoice->total_amount);
            if (Money::isZero($invoiceAmount) || ! Money::isZero(Money::percentOf($invoiceAmount, $snapshot['rate']))) {
                return null;
            }
            $paidAt = Carbon::parse($invoice->paid_at);
            $completedAt = null;
            foreach (DB::table('audit_logs')->where('action', 'affiliate.zero_accrual')->where('subject_type', 'App\\Models\\Invoice')
                ->where('subject_id', $invoice->id)->orderBy('id')->cursor() as $audit) {
                $after = json_decode($audit->after ?? '', true, flags: JSON_THROW_ON_ERROR);
                if ($audit->actor_type !== 'system' || $audit->actor_id !== null || $audit->created_at === null
                    || $audit->reason !== 'The paid invoice commission rounds to zero; no commission is payable.'
                    || ! is_array($after) || ($after['invoice_amount'] ?? null) !== $invoiceAmount
                    || ($after['rate'] ?? null) !== $snapshot['rate'] || ($after['amount'] ?? null) !== '0.00'
                    || ($after['referrer_id'] ?? null) !== $snapshot['referrer_id'] || Carbon::parse($audit->created_at)->lt($paidAt)) {
                    return null;
                }
                $completedAt ??= (string) $audit->created_at;
            }

            return $completedAt;
        } catch (JsonException|InvalidArgumentException) {
            // Incomplete or inconsistent history remains uncompleted for ordinary business revalidation.
            return null;
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'affiliate_zero_accrual_at')) {
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('affiliate_zero_accrual_at'));
        }
    }
};
