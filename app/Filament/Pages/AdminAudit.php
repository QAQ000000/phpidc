<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
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
        $validator = validator(request()->query(), [
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'action' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        abort_if($validator->fails(), 422, '审计筛选参数无效。');
        $data = $validator->validated();
        $filters = [];
        foreach (['actor_id', 'subject_id', 'subject_type', 'action'] as $key) {
            $filters[$key] = (string) ($data[$key] ?? '');
        }
        $records = DB::table('audit_logs')->select(['id', 'actor_id', 'actor_type', 'action', 'subject_type', 'subject_id', 'before', 'after', 'reason', 'ip', 'request_id', 'created_at'])
            ->when($filters['actor_id'] !== '', fn ($query) => $query->where('actor_id', $filters['actor_id']))
            ->when($filters['subject_id'] !== '', fn ($query) => $query->where('subject_id', $filters['subject_id']))
            ->when($filters['subject_type'] !== '', fn ($query) => $query->where('subject_type', $filters['subject_type']))
            ->when($filters['action'] !== '', fn ($query) => $query->where('action', $filters['action']))
            ->latest('id')->paginate(20)->appends(array_filter($filters, fn (string $value): bool => $value !== ''));
        $records->through(function (object $record): object {
            $record->before = AdminAuditProjection::json($record->before);
            $record->after = AdminAuditProjection::json($record->after);

            return $record;
        });

        $subjectTypes = [User::class => '客户账户', Invoice::class => '账单', Instance::class => '服务实例',
            Product::class => '商品', UpstreamApi::class => '上游连接', FinancialTransaction::class => '财务流水', FulfillmentTask::class => '履约任务'];
        if ($filters['subject_type'] !== '' && ! isset($subjectTypes[$filters['subject_type']])) {
            $subjectTypes[$filters['subject_type']] = '其他对象（当前筛选）';
        }

        return ['records' => $records, 'filters' => $filters, 'subjectTypes' => $subjectTypes];
    }
}
