<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminCustomerQueries;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class AdminCustomers extends Page
{
    protected static ?string $slug = 'customers';

    protected static ?string $title = '客户管理';

    protected string $view = 'filament.pages.admin-customers';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('customers.view') ?? false;
    }

    protected function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return app(AdminCustomerQueries::class)->listing($actor, request()->query());
    }
}
