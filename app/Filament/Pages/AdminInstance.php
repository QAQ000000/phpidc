<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminInstanceQueries;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class AdminInstance extends Page
{
    protected static ?string $slug = 'instances/{instance}';

    protected static ?string $title = '实例工作台';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.admin-instance';

    #[Locked]
    public int $instanceId = 0;

    public static function canAccess(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->isAccessEnabled() && $actor->can('upstream.view');
    }

    public function mount(string $instance): void
    {
        $id = filter_var($instance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $this->instanceId = $id;
    }

    public function getBreadcrumbs(): array
    {
        return ['/admin/upstream-instances' => '上游服务', '实例工作台'];
    }

    protected function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return app(AdminInstanceQueries::class)->detail($actor, $this->instanceId, request()->query());
    }
}
