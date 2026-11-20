<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
        $filters = $this->validatedFilters();
        $invoices = Invoice::query()->select(['id', 'invoice_no', 'user_id', 'instance_id', 'type', 'status', 'total_amount', 'credit_applied', 'gateway_amount', 'currency', 'created_at'])
            ->when($filters['user_id'] !== '', fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when($filters['invoice_no'] !== '', fn (Builder $query) => $query->where('invoice_no', $filters['invoice_no']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']));

        return ['invoices' => $invoices->orderByDesc('id')->paginate(20)->appends(array_filter($filters, fn (string $value): bool => $value !== '')),
            'filters' => $filters, 'statuses' => InvoiceStatus::cases(), 'types' => InvoiceType::cases()] + $this->invoiceLabels();
    }

    /** @return array{invoice_no: string, user_id: string, status: string, type: string} */
    protected function validatedFilters(): array
    {
        $validator = validator(request()->query(), [
            'invoice_no' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'type' => ['nullable', Rule::enum(InvoiceType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        // Invalid GET parameters must be an HTTP error, not Livewire form validation state.
        abort_if($validator->fails(), 422, '账单筛选参数无效。');
        $data = $validator->validated();

        return ['invoice_no' => (string) ($data['invoice_no'] ?? ''), 'user_id' => (string) ($data['user_id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''), 'type' => (string) ($data['type'] ?? '')];
    }

    /** @return array{statusLabels: array<string, string>, typeLabels: array<string, string>} */
    protected function invoiceLabels(): array
    {
        return [
            'statusLabels' => [InvoiceStatus::UNPAID->value => '待支付', InvoiceStatus::PAID->value => '已入账',
                InvoiceStatus::REFUNDED->value => '已退款', InvoiceStatus::CANCELLED->value => '已取消', InvoiceStatus::EXPIRED->value => '已过期'],
            'typeLabels' => [InvoiceType::NEW_ORDER->value => '新购', InvoiceType::RENEW->value => '续费',
                InvoiceType::UPGRADE->value => '升级', InvoiceType::DOWNGRADE->value => '降级', InvoiceType::WALLET_TOPUP->value => '钱包充值'],
        ];
    }
}
