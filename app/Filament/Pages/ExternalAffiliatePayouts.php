<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AffiliateWithdrawal;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ExternalAffiliatePayouts extends Page
{
    protected static ?string $slug = 'external-payouts';

    protected static ?string $title = '外部佣金提现';

    protected string $view = 'filament.pages.external-payouts';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('finance.view') ?? false;
    }

    protected function getViewData(): array
    {
        $status = request()->string('status')->toString();

        return ['records' => AffiliateWithdrawal::where('destination', 'external')->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(20, ['id', 'user_id', 'amount', 'status', 'payout_method', 'payout_intent_key', 'external_reference', 'processed_by', 'started_by', 'confirmed_by', 'created_at', 'completed_at'])->withQueryString()];
    }
}
