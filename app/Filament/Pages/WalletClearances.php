<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ReconciliationCase;
use App\Models\WalletClearance;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class WalletClearances extends Page
{
    protected static ?string $slug = 'wallet-clearances';

    protected static ?string $title = '钱包结清';

    protected string $view = 'filament.pages.wallet-clearances';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('finance.view') ?? false;
    }

    protected function getViewData(): array
    {
        return ['records' => WalletClearance::latest('id')->paginate(20, ['id', 'user_id', 'amount', 'status', 'scenario', 'payout_method', 'payout_intent_key', 'created_at', 'completed_at']),
            'sources' => Auth::user()?->can('finance.wallet-clearance.reconcile')
                ? ReconciliationCase::where('status', 'awaiting_clearance')->where('kind', 'payment_attention')->latest('id')->paginate(20, ['id', 'user_id', 'source_credit_transaction_id'], 'sources_page') : null];
    }
}
