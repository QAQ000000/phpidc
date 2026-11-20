<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\DescribeRenewalRecoveryAction;
use App\Models\Invoice;
use Livewire\Attributes\Locked;

class FinanceInvoice extends FinanceInvoices
{
    protected static ?string $slug = 'finance/invoice';

    protected static ?string $title = '账单核查与退款';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.finance-invoice';

    #[Locked]
    public int $invoiceId;

    public function mount(): void
    {
        $id = filter_var(request()->query('invoice_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $this->invoiceId = (int) $id;
    }

    protected function getViewData(): array
    {
        $filters = $this->validatedFilters();
        $validator = validator(request()->query(), [
            'attempts_page' => ['nullable', 'integer', 'min:1'],
            'callbacks_page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if($validator->fails(), 422, '支付记录分页参数无效。');
        $query = array_filter($filters, fn (string $value): bool => $value !== '');
        if ((int) request()->query('page', 1) > 1) {
            $query['page'] = (int) request()->query('page');
        }
        $returnUrl = '/admin/finance'.($query === [] ? '' : '?'.http_build_query($query));
        $query['invoice_id'] = $this->invoiceId;
        foreach ($validator->validated() as $key => $value) {
            if ((int) $value > 1) {
                $query[$key] = (int) $value;
            }
        }
        $invoice = Invoice::query()->select(['id', 'invoice_no', 'user_id', 'product_id', 'instance_id', 'status', 'type', 'currency',
            'total_amount', 'discount_amount', 'credit_applied', 'gateway_amount', 'upstream_expected_cost', 'gateway', 'paid_at', 'expires_at', 'created_at'])
            ->findOrFail($this->invoiceId);

        return ['invoice' => $invoice, 'returnUrl' => $returnUrl,
            'recoveryInfo' => app(DescribeRenewalRecoveryAction::class)->execute(Invoice::findOrFail($invoice->id)),
            'instance' => $invoice->instance()->first(['id', 'status', 'display_name']),
            'attempts' => $invoice->attempts()->orderByDesc('id')->paginate(10, ['id', 'gateway', 'out_trade_no', 'amount', 'status', 'paid_at'], 'attempts_page')->appends($query),
            'callbacks' => $invoice->callbacks()->orderByDesc('id')->paginate(10, ['id', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'], 'callbacks_page')->appends($query)] + $this->invoiceLabels();
    }
}
