<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\AdminAuditProjection;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminAudit extends Page
{
    protected static ?string $slug = 'audit';

    protected static ?string $title = '操作审计';

    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.pages.admin-audit';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('audit.view') ?? false;
    }

    protected function getViewData(): array
    {
        $records = DB::table('audit_logs')->select(['id', 'actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip', 'request_id', 'created_at'])
            ->when(request('actor_id'), fn ($q, $id) => $q->where('actor_id', (int) $id))
            ->when(request('subject_id'), fn ($q, $id) => $q->where('subject_id', (int) $id))
            ->when(request('action'), fn ($q, $action) => $q->where('action', mb_substr((string) $action, 0, 64)))
            ->latest('id')->paginate(20)->withQueryString();
        $records->through(function (object $record): object {
            $record->before = AdminAuditProjection::json($record->before);
            $record->after = AdminAuditProjection::json($record->after);

            return $record;
        });

        return ['records' => $records];
    }
}
