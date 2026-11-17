<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class FinanceInvoices extends Page
{
    protected static ?string $slug = 'finance';

    protected static ?string $title = '财务账单';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.finance-invoices';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('finance.view') ?? false;
    }

    protected function getViewData(): array
    {
        $invoices = Invoice::query()->select(['id', 'invoice_no', 'user_id', 'instance_id', 'type', 'status', 'total_amount', 'credit_applied', 'gateway_amount', 'currency', 'created_at'])
            ->when(request('user_id'), fn ($q, $id) => $q->where('user_id', (int) $id))
            ->when(request('invoice_no'), fn ($q, $number) => $q->where('invoice_no', mb_substr((string) $number, 0, 64)))
            ->when(InvoiceStatus::tryFrom((string) request('status')), fn ($q, $status) => $q->where('status', $status));

        return ['invoices' => $invoices->latest('id')->paginate(20)->withQueryString(), 'statuses' => InvoiceStatus::cases()];
    }
}
