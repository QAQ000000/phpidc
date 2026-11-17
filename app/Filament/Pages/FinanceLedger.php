<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\PaymentCallback;

class FinanceLedger extends FinanceInvoices
{
    protected static ?string $slug = 'finance/ledger';

    protected static ?string $title = '流水与支付回调';

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.pages.finance-ledger';

    protected function getViewData(): array
    {
        $ledger = FinancialTransaction::query()->select(['id', 'user_id', 'invoice_id', 'type', 'amount', 'balance_after', 'frozen_after', 'reference_key', 'meta', 'created_at'])
            ->when(request('user_id'), fn ($q, $id) => $q->where('user_id', (int) $id))
            ->when(request('invoice_id'), fn ($q, $id) => $q->where('invoice_id', (int) $id))
            ->when(TransactionType::tryFrom((string) request('type')), fn ($q, $type) => $q->where('type', $type));
        $callbacks = PaymentCallback::query()->select(['id', 'invoice_id', 'invoice_no', 'gateway', 'gateway_trade_no', 'amount', 'outcome', 'processed_at'])
            ->when(request('invoice_id'), fn ($q, $id) => $q->where('invoice_id', (int) $id))
            ->when(PaymentCallbackOutcome::tryFrom((string) request('outcome')), fn ($q, $outcome) => $q->where('outcome', $outcome));

        return ['ledger' => $ledger->latest('id')->paginate(20, ['*'], 'ledger_page')->withQueryString(),
            'callbacks' => $callbacks->latest('id')->paginate(20, ['*'], 'callbacks_page')->withQueryString(),
            'types' => TransactionType::cases(), 'outcomes' => PaymentCallbackOutcome::cases()];
    }
}
