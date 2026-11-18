<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\MatureAffiliateCommissionsAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateCommission;
use App\Models\Invoice;
use Illuminate\Console\Command;

class ProcessAffiliateCommand extends Command
{
    protected $signature = 'affiliate:process';

    protected $description = 'Recover paid commission accrual and mature due commissions';

    public function handle(): int
    {
        Invoice::where('status', InvoiceStatus::PAID)->where('type', InvoiceType::NEW_ORDER)->where('currency', 'CNY')->where('total_amount', '>', '0.00')->whereNotNull('line_items->affiliate')->whereNotIn('id', AffiliateCommission::select('invoice_id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('audit_logs')
                ->whereColumn('audit_logs.subject_id', 'invoices.id')->where('audit_logs.subject_type', Invoice::class)
                ->where('audit_logs.action', AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION))
            ->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
                }
            });
        AffiliateCommission::where('status', 'pending')->where('available_at', '<=', now())->chunkById(100, function ($commissions): void {
            foreach ($commissions as $commission) {
                app(MatureAffiliateCommissionsAction::class)->execute($commission->id);
            }
        });

        return self::SUCCESS;
    }
}
