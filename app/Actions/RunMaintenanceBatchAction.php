<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\MaintenanceBatchWork;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateCommission;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\RedemptionCode;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceBudget;
use LogicException;

class RunMaintenanceBatchAction implements MaintenanceBatchWork
{
    public function __construct(private readonly FairMaintenanceBatch $batch) {}

    public function execute(string $kind): array
    {
        $budget = new MaintenanceBudget;

        return match ($kind) {
            'invoices' => $this->batch->execute($kind, Invoice::where('status', InvoiceStatus::UNPAID)->where('expires_at', '<=', now()),
                'expires_at', fn (int $id) => app(ExpireUnpaidInvoicesAction::class)->executeOne($id), budget: $budget),
            'fulfillment' => $this->batch->execute($kind, FulfillmentTask::where(function ($query): void {
                $query->where(fn ($pending) => $pending->where('status', FulfillmentTaskStatus::PENDING)->where('created_at', '<', now()->subMinute())
                    ->where(fn ($due) => $due->whereNull('available_at')->orWhere('available_at', '<=', now())))
                    ->orWhere(fn ($running) => $running->where('status', FulfillmentTaskStatus::RUNNING)->where('lease_expires_at', '<', now()));
            }), 'created_at', fn (int $id) => app(DispatchStalePendingTasksAction::class)->executeOne($id), budget: $budget),
            'cards' => $this->batch->execute($kind, RedemptionCode::where('status', 'reserved')->where('reserved_until', '<', now()),
                'reserved_until', fn (int $id) => app(ReapRedemptionReservationsAction::class)->executeOne($id), budget: $budget),
            'affiliate-accrue' => $this->batch->execute($kind, Invoice::where('status', InvoiceStatus::PAID)->where('type', InvoiceType::NEW_ORDER)
                ->where('currency', 'CNY')->where('total_amount', '>', '0.00')->whereNotNull('line_items->affiliate')
                ->whereNotIn('id', AffiliateCommission::select('invoice_id'))
                ->whereNull('affiliate_zero_accrual_at'),
                'paid_at', fn (int $id) => app(AccrueAffiliateCommissionAction::class)->execute($id), budget: $budget),
            'affiliate-mature' => $this->batch->execute($kind, AffiliateCommission::where('status', 'pending')->where('available_at', '<=', now()),
                'available_at', fn (int $id) => app(MatureAffiliateCommissionsAction::class)->execute($id), budget: $budget),
            'lifecycle' => app(DispatchInstanceLifecycleAction::class)->executeMaintenance($budget),
            'catalog' => app(DispatchClassicCatalogAction::class)->executeMaintenance($budget),
            'notifications' => app(DispatchPendingNotificationsAction::class)->run($budget),
            default => throw new LogicException('Unknown maintenance kind.'),
        };
    }
}
