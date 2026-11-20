<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\UpstreamApi;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class UpstreamInstances extends Page
{
    protected static ?string $slug = 'upstream-instances';

    protected static ?string $title = '经典 ZJMF 服务与任务';

    protected string $view = 'filament.pages.upstream-instances';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('upstream.view') ?? false;
    }

    protected function getViewData(): array
    {
        $validator = validator(request()->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(InstanceStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        // Invalid GET filters need an HTTP error instead of Livewire form validation state.
        abort_if($validator->fails(), 422, '实例筛选参数无效。');
        $data = $validator->validated();
        $userId = (string) ($data['user_id'] ?? '');
        $filters = [
            'q' => (string) ($data['q'] ?? ''),
            'account_id' => (string) ($data['account_id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
        ];
        if ($userId !== '') {
            $filters['user_id'] = $userId;
        }
        $query = array_filter($filters, fn (string $value): bool => $value !== '');
        $columns = ['id', 'display_name', 'upstream_api_id', 'upstream_instance_id', 'status',
            'next_due_at', 'recurring_amount', 'currency', 'billing_cycle'];
        $instances = Instance::where('driver_type', DriverType::ZJMF_API)->select($columns)
            ->when($filters['q'] !== '', function (Builder $instances) use ($filters): void {
                $instances->where(function (Builder $search) use ($filters): void {
                    $search->where('display_name', 'like', '%'.$filters['q'].'%')
                        ->orWhere('upstream_instance_id', $filters['q']);
                    if (ctype_digit($filters['q'])) {
                        $search->orWhere('id', $filters['q']);
                    }
                });
            })
            ->when($filters['account_id'] !== '', fn (Builder $instances) => $instances->where('upstream_api_id', $filters['account_id']))
            ->when($userId !== '', fn (Builder $instances) => $instances->where('user_id', $userId))
            ->when($filters['status'] !== '', fn (Builder $instances) => $instances->where('status', $filters['status']))
            ->orderByDesc('id')->paginate(20)->appends($query);
        $selectedInstance = null;
        $selectedId = request()->query('instance');
        if ($selectedId !== null && $selectedId !== '') {
            $selectedKey = filter_var($selectedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($selectedKey === false, 404);
            $selectedInstance = Instance::where('driver_type', DriverType::ZJMF_API)
                ->when($userId !== '', fn (Builder $instances) => $instances->where('user_id', $userId))
                ->select([...$columns, 'user_id', 'product_id', 'hostname', 'primary_ip', 'last_synced_at', 'suspend_reason'])
                ->findOrFail((int) $selectedKey);
        }
        if (($data['page'] ?? 1) > 1) {
            $query['page'] = (int) $data['page'];
        }

        return ['accounts' => UpstreamApi::where('driver', DriverType::ZJMF_API)->get(['id', 'name', 'is_active']),
            'instances' => $instances, 'filters' => $filters, 'statuses' => InstanceStatus::cases(),
            'statusLabels' => ['Pending' => '待开通', 'Active' => '运行中', 'Suspended' => '已暂停',
                'Cancelled' => '已取消', 'Fraud' => '欺诈', 'Deleted' => '已删除', 'ProvisionFailed' => '开通失败'],
            'taskStatusLabels' => ['pending' => '待执行', 'running' => '执行中', 'success' => '已完成', 'failed' => '失败', 'stale' => '已失效'],
            'hasFilters' => array_filter($filters, fn (string $value): bool => $value !== '') !== [],
            'selectedInstance' => $selectedInstance,
            'detailReturnUrl' => '/admin/upstream-instances'.($query === [] ? '' : '?'.http_build_query($query)),
            'tasks' => FulfillmentTask::where('driver_type', DriverType::ZJMF_API)
                ->when($selectedInstance !== null, fn (Builder $tasks) => $tasks->where('instance_id', $selectedInstance->id))
                ->latest('id')->limit(40)->get(['id', 'instance_id', 'invoice_id', 'action', 'status', 'error_message', 'attempts'])];
    }
}
