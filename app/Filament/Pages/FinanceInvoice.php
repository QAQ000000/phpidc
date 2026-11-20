<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\DescribeRenewalRecoveryAction;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Support\AdminFinancialAccess;
use App\Support\AdminWorkspaceNavigation;
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

    public function getHeading(): string
    {
        return '';
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
        $tab = AdminWorkspaceNavigation::tab('invoice', request()->query());
        abort_if($tab === null, 422, '账单页签无效。');
        $actor = Auth::user();
        abort_unless($actor instanceof User && $actor->can('finance.view'), 403);
        abort_if($tab === 'tasks' && ! $actor->can('tasks.view'), 403);
        $invoice = Invoice::query()->select(['id', 'invoice_no', 'user_id', 'product_id', 'instance_id', 'status', 'type', 'currency',
            'total_amount', 'discount_amount', 'credit_applied', 'gateway_amount', 'upstream_expected_cost', 'gateway', 'paid_at', 'expires_at', 'created_at'])
            ->findOrFail($this->invoiceId);
        abort_if($tab === 'tasks' && $invoice->type === InvoiceType::WALLET_TOPUP, 403);
        abort_if($tab === 'recovery' && $invoice->type !== InvoiceType::RENEW, 403);
        $listQuery = array_filter($filters, fn (string $value): bool => $value !== '');
        if ((int) request()->query('page', 1) > 1) {
            $listQuery['page'] = (int) request()->query('page');
        }
        $returnUrl = '/admin/finance'.($listQuery === [] ? '' : '?'.http_build_query($listQuery));
        $workspaceQuery = AdminWorkspaceNavigation::query($actor, 'invoice', request()->query());
        $workspaceQuery['tab'] = $tab;
        $query = ['invoice_id' => $invoice->id] + $workspaceQuery;
        $tabs = ['overview' => '概览', 'items' => '账单明细', 'payments' => '支付记录', 'ledger' => '资金流水'];
        if ($actor->can('tasks.view') && $invoice->type !== InvoiceType::WALLET_TOPUP) {
            $tabs['tasks'] = '履约任务';
        }
        if ($invoice->type === InvoiceType::RENEW) {
            $tabs['recovery'] = '续费恢复';
        }
        $tabLinks = [];
        foreach ($tabs as $key => $label) {
            $tabLinks[$key] = ['label' => $label, 'url' => AdminWorkspaceNavigation::url('invoice', $invoice->id, ['tab' => $key] + $workspaceQuery)];
        }
        $restoreOffline = session()->has('errors') && session()->getOldInput('_admin_form') === 'offline-receipt'
            && is_scalar(session()->getOldInput('invoice_id'))
            && (string) session()->getOldInput('invoice_id') === (string) $invoice->id;
        $restoreRefund = session()->has('errors') && session()->getOldInput('ui_form') === 'refund-'.$invoice->id;
        $needsActions = $tab === 'overview' || $restoreOffline || $restoreRefund;
        $canRecordOffline = $actor->can('finance.offline-receipt');
        $offlineAllowed = false;
        $offlineReason = null;
        $offlineReceipt = null;
        if ($needsActions) {
            $receipt = OfflineInvoiceReceipt::query()->where('invoice_id', $invoice->id)->first(['id', 'invoice_id', 'user_id', 'processed_by',
                'amount', 'currency', 'external_channel', 'external_reference', 'received_at', 'payment_transaction_id',
                'settlement_transaction_id', 'fulfillment_task_id', 'created_at']);
            if ($receipt !== null) {
                $offlineReceipt = [
                    'id' => $receipt->id, 'amount' => $receipt->amount, 'currency' => $receipt->currency,
                    'external_channel' => $receipt->external_channel,
                    'masked_reference' => '••••'.(mb_strlen((string) $receipt->external_reference) > 4 ? mb_substr((string) $receipt->external_reference, -4) : ''),
                    'received_at' => $receipt->received_at->format('Y-m-d H:i:s'), 'processed_by' => $receipt->processed_by,
                    'payment_transaction_id' => $receipt->payment_transaction_id, 'settlement_transaction_id' => $receipt->settlement_transaction_id,
                    'fulfillment_task_id' => $receipt->fulfillment_task_id,
                ];
            } elseif ($canRecordOffline) {
                $owner = User::withTrashed()->find($invoice->user_id, ['id', 'deleted_at', 'anonymized_at', 'disabled_at']);
                $offlineReason = $owner !== null && app(AdminFinancialAccess::class)->isManageableTarget($actor, $owner)
                    ? app(OfflineInvoiceReceiptRules::class)->reason(Invoice::findOrFail($invoice->id))
                    : '此账户不属于可登记线下收款的普通客户。';
                $offlineAllowed = $offlineReason === null;
            }
        }
        $canRefund = $needsActions && $invoice->type !== InvoiceType::WALLET_TOPUP
            && $invoice->status === InvoiceStatus::PAID && $actor->can('refund-invoice', $invoice);

        return ['invoice' => $invoice, 'returnUrl' => $returnUrl, 'tab' => $tab, 'tabLinks' => $tabLinks,
            'workspaceQuery' => $workspaceQuery, 'canRefund' => $canRefund,
            'recoveryInfo' => $tab === 'recovery' ? app(DescribeRenewalRecoveryAction::class)->execute(Invoice::findOrFail($invoice->id)) : null,
            'invoiceItems' => $tab === 'items' ? $this->invoiceItems($invoice) : [],
            'receiptFacts' => $tab === 'overview' ? $this->receiptFacts($invoice) : [],
            'offlineReceipt' => $offlineReceipt, 'canRecordOffline' => $canRecordOffline,
            'offlineReason' => $offlineReason, 'offlineAllowed' => $offlineAllowed,
            'ledger' => $tab === 'ledger' ? FinancialTransaction::query()->where('invoice_id', $invoice->id)->latest('id')
                ->paginate(10, ['id', 'type', 'amount', 'balance_after', 'frozen_after', 'reference_key', 'created_at'], 'ledger_page')->appends($query) : null,
            'tasks' => $tab === 'tasks' ? $invoice->fulfillmentTasks()->latest('id')
                ->paginate(10, ['id', 'instance_id', 'status', 'action', 'attempts', 'max_attempts', 'available_at', 'executed_at'], 'tasks_page')->appends($query) : null,
            'instance' => ($tab === 'overview' || $canRefund) ? $invoice->instance()->first(['id', 'status', 'display_name']) : null,
            'attempts' => $tab === 'payments' ? $invoice->attempts()->orderByDesc('id')
                ->paginate(10, ['id', 'gateway', 'out_trade_no', 'amount', 'status', 'paid_at'], 'attempts_page')->appends($query) : null,
            'callbacks' => $tab === 'payments' ? $invoice->callbacks()->orderByDesc('id')
                ->paginate(10, ['id', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'], 'callbacks_page')->appends($query) : null] + $this->invoiceLabels();
    }

    private function invoiceItems(Invoice $invoice): array
    {
        $snapshot = Invoice::query()->findOrFail($invoice->id, ['id', 'line_items'])->line_items ?? [];
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

        return $items;
    }

    /** Original invoice postings only: late/duplicate callback wallet credits are not principal receipts. */
    private function receiptFacts(Invoice $invoice): array
    {
        $types = ['settle:'.$invoice->id => TransactionType::CREDIT_SETTLE,
            'payment:'.$invoice->id => TransactionType::PAYMENT];
        if ($invoice->type === InvoiceType::WALLET_TOPUP) {
            $types['topup:'.$invoice->id] = TransactionType::CREDIT_IN;
        }
        $facts = [];
        foreach (FinancialTransaction::query()->where('invoice_id', $invoice->id)->where('user_id', $invoice->user_id)
            ->whereIn('reference_key', array_keys($types))->get(['id', 'type', 'amount', 'reference_key', 'created_at']) as $entry) {
            if ($entry->type !== $types[$entry->reference_key]) {
                continue;
            }
            $facts[] = ['id' => $entry->id, 'amount' => $entry->amount, 'created_at' => $entry->created_at,
                'label' => match ($entry->type) {
                    TransactionType::CREDIT_SETTLE => '钱包已结算', TransactionType::CREDIT_IN => '充值已入钱包',
                    default => '付款流水已记录',
                }];
        }

        return $facts;
    }

    private function displayAmount(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{1,10}\.\d{2}$/D', $value) === 1 ? $value : '—';
    }
}
