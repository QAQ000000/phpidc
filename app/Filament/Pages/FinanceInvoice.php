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
        $this->invoiceId = (int) request('invoice_id');
    }

    protected function getViewData(): array
    {
        $invoice = Invoice::query()->select(['id', 'invoice_no', 'user_id', 'product_id', 'instance_id', 'status', 'type', 'currency',
            'total_amount', 'discount_amount', 'credit_applied', 'gateway_amount', 'upstream_expected_cost', 'gateway', 'paid_at', 'expires_at', 'created_at'])
            ->findOrFail($this->invoiceId);

        return ['invoice' => $invoice, 'recoveryInfo' => app(DescribeRenewalRecoveryAction::class)->execute(Invoice::findOrFail($invoice->id)),
            'instance' => $invoice->instance()->first(['id', 'status', 'display_name']),
            'attempts' => $invoice->attempts()->latest('id')->paginate(10, ['id', 'gateway', 'out_trade_no', 'amount', 'status', 'paid_at'], 'attempts_page')->withQueryString(),
            'callbacks' => $invoice->callbacks()->latest('id')->paginate(10, ['id', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'], 'callbacks_page')->withQueryString()];
    }
}
