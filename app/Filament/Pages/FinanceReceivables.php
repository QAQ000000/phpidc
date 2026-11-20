<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\TransactionType;
use App\Exceptions\ReceivableScanBudgetExceeded;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\ReceivableAdmission;
use App\Services\ReceivableEvidenceScanner;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $formError = session('errors')?->any() && in_array(old('_admin_form'), ['receivable-settlement', 'receivable-reversal', 'receivable-review'], true)
            ? old('_admin_form') : null;
        $submittedRoot = filter_var(old('clawback_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if (request('clawback') !== null && request()->integer('clawback') !== $submittedRoot) {
            $formError = null;
        }
        if (request('corrects_settlement') !== null && ($formError !== 'receivable-settlement'
            || request()->integer('corrects_settlement') !== (int) old('corrects_settlement_id'))) {
            $formError = null;
        }
        $selectedId = request()->integer('clawback') ?: ($formError !== null ? $submittedRoot : null);
        $selected = $selectedId ? FinancialTransaction::where('type', TransactionType::AFFILIATE_CLAWBACK)->findOrFail($selectedId) : null;
        $correctionId = request()->integer('corrects_settlement') ?: ($formError === 'receivable-settlement' ? (int) old('corrects_settlement_id') : 0);
        $correction = $correctionId ? AffiliateReceivableSettlement::with('reversal')->whereHas('reversal')->findOrFail($correctionId) : null;
        $check = null;
        try {
            $projections = app(ReceivableEvidenceScanner::class)->project($roots->getCollection(), hrtime(true) + 2000000000);
        } catch (ReceivableScanBudgetExceeded) {
            $projections = collect();
        }
        $projection = $selected === null ? null : $projections->get($selected->id);
        $selectedUser = $selected === null ? null : User::withTrashed()->findOrFail($selected->user_id);
        if ($selected !== null && $projection === null) {
            try {
                $scan = app(ReceivableAdmission::class)->prepare($selectedUser, 'receivable-detail', 'root-'.$selected->id, [], false, true, [$selected->id]);
                $projection = $scan['projections'][$selected->id] ?? null;
            } catch (ValidationException $error) {
                $check = $error->errors();
            }
        }

        return ['roots' => $roots, 'projections' => $projections, 'selected' => $selected, 'selectedUser' => $selectedUser, 'correction' => $correction,
            'projection' => $projection, 'receivableCheck' => $check, 'formError' => $formError,
            'settlements' => $selected === null ? null : AffiliateReceivableSettlement::with('reversal')->where('clawback_transaction_id', $selected->id)
                ->orderBy('id')->paginate(20, ['*'], 'settlements_page')->withQueryString(),
            'requestId' => (string) Str::ulid()];
    }
}
