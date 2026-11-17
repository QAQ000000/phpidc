<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

abstract class InventoryPage extends Page
{
    public static function canAccess(): bool
    {
        return Auth::user()?->can('inventory.view') ?? false;
    }
}
