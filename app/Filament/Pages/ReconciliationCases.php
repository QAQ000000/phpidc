<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ReconciliationCases extends Page
{
    protected static ?string $slug = 'reconciliations';

    protected static ?string $title = '业务异常核对';

    protected string $view = 'filament.pages.reconciliations';

    public static function canAccess(): bool
    {
        return (Auth::user()?->can('finance.view') ?? false) || (Auth::user()?->can('upstream.view') ?? false);
    }

    protected function getViewData(): array
    {
        $query = ReconciliationCase::where(function (Builder $query): void {
            if (Auth::user()?->can('finance.view')) {
                $query->where('kind', 'payment_attention');
            }
            if (Auth::user()?->can('upstream.view')) {
                $query->orWhere('kind', 'binding_orphan');
            }
        });
        $selectedId = request()->integer('case');
        if (! request()->filled('case') && session('errors')?->any()
            && in_array(old('_admin_form'), ['reconciliation-payment', 'reconciliation-reopen', 'reconciliation-binding'], true)) {
            $selectedId = filter_var(old('record_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        }
        $selected = $selectedId ? (clone $query)->findOrFail($selectedId) : null;

        return ['records' => $query->latest('id')->paginate(20)->withQueryString(), 'selected' => $selected,
            'entries' => $selected === null ? null : ReconciliationEntry::where('case_id', $selected->id)->latest('sequence')
                ->paginate(20, ['id', 'sequence', 'action', 'actor_id', 'reason', 'created_at'], 'history_page')->withQueryString()];
    }
}
