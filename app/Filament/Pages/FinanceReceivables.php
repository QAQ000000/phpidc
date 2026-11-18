<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Services\AffiliateReceivableBalance;
use Illuminate\Support\Str;

class FinanceReceivables extends FinanceInvoices
{
    protected static ?string $slug = 'finance/receivables';

    protected static ?string $title = '退款佣金应收';

    protected string $view = 'filament.pages.finance-receivables';

    protected function getViewData(): array
    {
        $roots = FinancialTransaction::where('type', TransactionType::AFFILIATE_CLAWBACK)
            ->when(request()->integer('user_id'), fn ($query, $id) => $query->where('user_id', $id))
            ->latest('id')->paginate(20)->withQueryString();
        $selected = request()->integer('clawback') ? FinancialTransaction::where('type', TransactionType::AFFILIATE_CLAWBACK)->findOrFail(request()->integer('clawback')) : null;
        $balance = app(AffiliateReceivableBalance::class);

        return ['roots' => $roots, 'projections' => $balance->many($roots->getCollection()), 'selected' => $selected,
            'projection' => $selected === null ? null : $balance->project($selected),
            'settlements' => $selected === null ? collect() : AffiliateReceivableSettlement::with('reversal')->where('clawback_transaction_id', $selected->id)->orderBy('id')->get(),
            'requestId' => (string) Str::ulid()];
    }
}
