<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\AffiliateWithdrawal;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\ReconciliationCase;
use App\Models\Ticket;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Auth;

class AdminDashboard extends Dashboard
{
    protected static ?string $title = '经营总览';

    protected string $view = 'filament.pages.admin-dashboard';

    public static function canAccess(): bool
    {
        return Auth::user()?->canAccessPanel(Filament::getPanel('admin')) ?? false;
    }

    protected function getViewData(): array
    {
        $now = CarbonImmutable::now();
        $actor = Auth::user();
        $data = ['updatedAt' => $now, 'finance' => null, 'services' => null, 'tasks' => null, 'support' => null];

        // Authorize each domain before even querying it. No remote reads or secret projections.
        if ($actor?->can('finance.view')) {
            $data['finance'] = $this->financeOverview($now);
        }
        if ($actor?->can('upstream.view')) {
            $classic = Instance::where('driver_type', DriverType::ZJMF_API);
            $data['services'] = [
                'active' => (clone $classic)->where('status', InstanceStatus::ACTIVE)->count(),
                'due' => (clone $classic)->whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED])
                    ->whereBetween('next_due_at', [$now, $now->addDays(7)])->count(),
            ];
        }
        if ($actor?->can('tasks.view')) {
            $data['tasks'] = ['failed' => FulfillmentTask::where('status', FulfillmentTaskStatus::FAILED)->count()];
        }
        if ($actor?->can('support.view')) {
            $open = Ticket::where('status', 'open');
            $data['support'] = ['open' => (clone $open)->count(),
                'tickets' => $open->latest('id')->limit(5)->get(['id', 'subject', 'user_id', 'created_at'])];
        }

        return $data;
    }

    private function financeOverview(CarbonImmutable $now): array
    {
        $today = Money::zero();
        $month = Money::zero();
        // Group equal invoice amounts and count them; SUM on money would return floats on SQLite.
        // Counts stay integral, and Money owns all multiplication/addition. Never load order snapshots.
        $groups = Invoice::where('currency', 'CNY')->whereIn('type', [InvoiceType::NEW_ORDER, InvoiceType::RENEW, InvoiceType::UPGRADE])
            ->whereIn('status', [InvoiceStatus::PAID, InvoiceStatus::REFUNDED])
            ->where('paid_at', '>=', $now->startOfMonth())->where('paid_at', '<=', $now)
            ->select('total_amount')->selectRaw('COUNT(*) AS month_count')
            ->selectRaw('SUM(CASE WHEN paid_at >= ? THEN 1 ELSE 0 END) AS today_count', [$now->startOfDay()])
            ->groupBy('total_amount')->cursor();
        foreach ($groups as $group) {
            $today = Money::add($today, Money::mul($group->total_amount, (string) $group->getAttribute('today_count')));
            $month = Money::add($month, Money::mul($group->total_amount, (string) $group->getAttribute('month_count')));
        }

        return ['today' => $today, 'month' => $month,
            'unpaid' => Invoice::where('status', InvoiceStatus::UNPAID)->count(),
            'external_pending' => AffiliateWithdrawal::where('destination', 'external')->where('status', 'pending')->count(),
            'wallet_pending' => AffiliateWithdrawal::where('destination', 'wallet')->where('status', 'pending')->count(),
            'payment_attention' => ReconciliationCase::where('kind', 'payment_attention')->whereIn('status', ['open', 'awaiting_clearance'])->count(),
            'invoices' => Invoice::latest('id')->limit(8)->get(['id', 'invoice_no', 'user_id', 'type', 'status', 'total_amount', 'currency', 'created_at']),
        ];
    }
}
