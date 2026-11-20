<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Instance;
use App\Models\User;
use App\Support\AdminMachineAccess;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class AdminInstanceMachine extends Page
{
    protected static ?string $slug = 'instance-machine-controls';

    protected static ?string $title = '管理员机器操作';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.admin-instance-machine';

    #[Locked]
    public int $instanceId;

    #[Locked]
    public array $controls = [];

    public static function canAccess(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->isAccessEnabled() && $actor->can('upstream.view') && $actor->can('upstream.machine-control');
    }

    public function mount(int $instanceId = 0, array $controls = []): void
    {
        abort_unless($instanceId > 0 && $controls !== [], 404);
        $this->instanceId = $instanceId;
        $this->controls = $controls;
    }

    protected function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        ['instance' => $instance] = app(AdminMachineAccess::class)->authorize($actor, Instance::findOrFail($this->instanceId));

        return ['instance' => $instance, 'controls' => $this->controls];
    }
}
