<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DriverType;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

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
        return ['instances' => Instance::where('driver_type', DriverType::ZJMF_API)->paginate(20, ['id', 'display_name', 'upstream_api_id', 'upstream_instance_id', 'status', 'next_due_at', 'recurring_amount', 'currency']),
            'tasks' => FulfillmentTask::where('driver_type', DriverType::ZJMF_API)->latest('id')->limit(40)->get(['id', 'instance_id', 'invoice_id', 'action', 'status', 'error_message', 'attempts'])];
    }
}
