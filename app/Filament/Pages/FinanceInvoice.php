<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\DescribeRenewalRecoveryAction;
use App\Enums\InvoiceType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Support\AdminFinancialAccess;
use App\Support\OfflineInvoiceReceiptRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class FinanceInvoice extends FinanceInvoices
{
    protected static ?string $slug = 'finance/invoice';

    protected static ?string $title = '账单工作台';

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
            'ledger_page' => ['nullable', 'integer', 'min:1'],
            'tasks_page' => ['nullable', 'integer', 'min:1'],
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
        $actor = Auth::user();
        $source = Invoice::findOrFail($invoice->id);
        $snapshot = $source->line_items ?? [];
        $items = [];
        foreach (array_slice(is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [], 0, 100) as $item) {
            if (! is_array($item) || ! in_array($item['kind'] ?? null, ['product', 'setup_fee'], true)) {
                continue;
            }
            $items[] = ['label' => is_string($item['label'] ?? null) ? mb_substr($item['label'], 0, 255) : '账单项目',
                'qty' => is_int($item['qty'] ?? null) && $item['qty'] >= 0 ? (string) $item['qty'] : '—',
                'unit_price' => $this->displayAmount($item['unit_price'] ?? null),
                'amount' => $this->displayAmount($item['amount'] ?? null)];
        }
        $receipt = OfflineInvoiceReceipt::query()->where('invoice_id', $invoice->id)->first(['id', 'invoice_id', 'user_id', 'processed_by',
            'amount', 'currency', 'external_channel', 'external_reference', 'received_at', 'payment_transaction_id',
            'settlement_transaction_id', 'fulfillment_task_id', 'created_at']);
        $offlineReceipt = $receipt === null ? null : [
            'id' => $receipt->id, 'amount' => $receipt->amount, 'currency' => $receipt->currency,
            'external_channel' => $receipt->external_channel,
            'masked_reference' => '••••'.(mb_strlen((string) $receipt->external_reference) > 4 ? mb_substr((string) $receipt->external_reference, -4) : ''),
            'received_at' => $receipt->received_at->format('Y-m-d H:i:s'), 'processed_by' => $receipt->processed_by,
            'payment_transaction_id' => $receipt->payment_transaction_id, 'settlement_transaction_id' => $receipt->settlement_transaction_id,
            'fulfillment_task_id' => $receipt->fulfillment_task_id,
        ];
        $canRecordOffline = $actor instanceof User && $actor->can('finance.offline-receipt');
        $offlineReason = null;
        if ($canRecordOffline && $receipt === null) {
            $owner = User::withTrashed()->find($invoice->user_id, ['id', 'deleted_at', 'anonymized_at', 'disabled_at']);
            $offlineReason = $owner !== null && app(AdminFinancialAccess::class)->isManageableTarget($actor, $owner)
                ? app(OfflineInvoiceReceiptRules::class)->reason($source) : '此账户不属于可登记线下收款的普通客户。';
        }
        $canViewTasks = $actor instanceof User && $actor->can('tasks.view') && $invoice->type !== InvoiceType::WALLET_TOPUP;

        return ['invoice' => $invoice, 'returnUrl' => $returnUrl,
            'recoveryInfo' => app(DescribeRenewalRecoveryAction::class)->execute($source),
            'invoiceItems' => $items, 'offlineReceipt' => $offlineReceipt,
            'canRecordOffline' => $canRecordOffline, 'offlineReason' => $offlineReason,
            'offlineAllowed' => $canRecordOffline && $receipt === null && $offlineReason === null,
            'ledger' => FinancialTransaction::query()->where('invoice_id', $invoice->id)->latest('id')
                ->paginate(10, ['id', 'type', 'amount', 'balance_after', 'frozen_after', 'reference_key', 'created_at'], 'ledger_page')->appends($query),
            'tasks' => $canViewTasks ? $invoice->fulfillmentTasks()->latest('id')
                ->paginate(10, ['id', 'instance_id', 'status', 'action', 'attempts', 'max_attempts', 'available_at', 'executed_at'], 'tasks_page')->appends($query) : null,
            'instance' => $invoice->instance()->first(['id', 'status', 'display_name']),
            'attempts' => $invoice->attempts()->orderByDesc('id')->paginate(10, ['id', 'gateway', 'out_trade_no', 'amount', 'status', 'paid_at'], 'attempts_page')->appends($query),
            'callbacks' => $invoice->callbacks()->orderByDesc('id')->paginate(10, ['id', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'], 'callbacks_page')->appends($query)] + $this->invoiceLabels();
    }

    private function displayAmount(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{1,10}\.\d{2}$/D', $value) === 1 ? $value : '—';
    }
}
