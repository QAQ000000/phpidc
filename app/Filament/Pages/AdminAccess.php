<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AdminRoles;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AdminAccess extends Page
{
    protected static ?string $slug = 'access';

    protected static ?string $title = '账户角色管理';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.admin-access';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('access.view') ?? false;
    }

    protected function getViewData(): array
    {
        return ['users' => User::query()->select(['id', 'name', 'email'])->with('roles:id,name,guard_name')
            ->when(request('user_id'), fn ($q, $id) => $q->whereKey((int) $id))
            ->when(request('email'), fn ($q, $email) => $q->where('email', mb_substr((string) $email, 0, 255)))
            ->latest('id')->paginate(20)->withQueryString(),
            'roles' => AdminRoles::webAssignable(), 'definitions' => AdminRoles::permissions(),
            'roleLabels' => AdminRoles::labels(), 'presets' => AdminRoles::presets()];
    }
}
