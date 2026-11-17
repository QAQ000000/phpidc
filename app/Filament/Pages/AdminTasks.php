<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\FulfillmentTaskStatus;
use App\Models\FulfillmentTask;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminTasks extends Page
{
    protected static ?string $slug = 'tasks';

    protected static ?string $title = '任务监控与恢复';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.pages.admin-tasks';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('tasks.view') ?? false;
    }

    protected function getViewData(): array
    {
        $tasks = FulfillmentTask::query()->select(['id', 'user_id', 'invoice_id', 'instance_id', 'driver_type', 'upstream_api_id',
            'action', 'status', 'attempts', 'max_attempts', 'available_at', 'lease_expires_at', 'executed_at', 'created_at'])
            ->when(request('task_id'), fn ($q, $id) => $q->whereKey((int) $id))
            ->when(request('instance_id'), fn ($q, $id) => $q->where('instance_id', (int) $id))
            ->when(request('invoice_id'), fn ($q, $id) => $q->where('invoice_id', (int) $id))
            ->when(FulfillmentTaskStatus::tryFrom((string) request('status')), fn ($q, $status) => $q->where('status', $status));

        return ['tasks' => $tasks->latest('id')->paginate(20, ['*'], 'tasks_page')->withQueryString(),
            'failures' => DB::table('failed_jobs')->select(['id', 'uuid', 'connection', 'queue', 'failed_at'])->latest('id')->paginate(10, ['*'], 'failures_page')->withQueryString(),
            'statuses' => FulfillmentTaskStatus::cases()];
    }
}
