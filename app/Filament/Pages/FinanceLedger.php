<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\PaymentCallback;
use App\Services\AffiliateReceivableBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class FinanceLedger extends FinanceInvoices
{
    protected static ?string $slug = 'finance/ledger';

    protected static ?string $title = '流水与支付回调';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.finance-ledger';

    protected function getViewData(): array
    {
        $validator = validator(request()->query(), [
            'user_id' => ['nullable', 'integer', 'min:1'],
            'invoice_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'outcome' => ['nullable', Rule::enum(PaymentCallbackOutcome::class)],
            'ledger_page' => ['nullable', 'integer', 'min:1'],
            'callbacks_page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if($validator->fails(), 422, '流水与回调筛选参数无效。');
        $data = $validator->validated();
        $filters = [];
        foreach (['user_id', 'invoice_id', 'type', 'outcome'] as $key) {
            $filters[$key] = (string) ($data[$key] ?? '');
        }
        $query = array_filter($filters, fn (string $value): bool => $value !== '');
        foreach (['ledger_page', 'callbacks_page'] as $page) {
            if (($data[$page] ?? 1) > 1) {
                $query[$page] = (int) $data[$page];
            }
        }
        $ledger = FinancialTransaction::query()->select(['id', 'user_id', 'invoice_id', 'type', 'amount', 'balance_after', 'frozen_after', 'reference_key', 'meta', 'created_at'])
            ->when($filters['user_id'] !== '', fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when($filters['invoice_id'] !== '', fn (Builder $query) => $query->where('invoice_id', $filters['invoice_id']))
            ->when($filters['type'] !== '', fn (Builder $query) => $query->where('type', $filters['type']));
        $callbacks = PaymentCallback::query()->select(['id', 'invoice_id', 'invoice_no', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'])
            ->when($filters['user_id'] !== '', fn (Builder $query) => $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('user_id', $filters['user_id'])))
            ->when($filters['invoice_id'] !== '', fn (Builder $query) => $query->where('invoice_id', $filters['invoice_id']))
            ->when($filters['outcome'] !== '', fn (Builder $query) => $query->where('outcome', $filters['outcome']));

        $entries = $ledger->latest('id')->paginate(20, ['*'], 'ledger_page')->appends($query);

        return ['ledger' => $entries,
            'receivables' => app(AffiliateReceivableBalance::class)->many($entries->getCollection()->filter(fn (FinancialTransaction $entry): bool => $entry->type === TransactionType::AFFILIATE_CLAWBACK)),
            'callbacks' => $callbacks->latest('id')->paginate(20, ['*'], 'callbacks_page')->appends($query),
            'types' => TransactionType::cases(), 'outcomes' => PaymentCallbackOutcome::cases(), 'filters' => $filters];
    }
}
