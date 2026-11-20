<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdminCustomerQueries;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

class AdminCustomer extends AdminCustomers
{
    protected static ?string $slug = 'customers/{customerId}';

    protected static ?string $title = '客户详情';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.admin-customer';

    #[Locked]
    public int $selectedCustomerId;

    public function getHeading(): string
    {
        return '';
    }

    public function mount(string $customerId): void
    {
        $id = filter_var($customerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $this->selectedCustomerId = $id;
    }

    protected function getViewData(): array
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);

        return app(AdminCustomerQueries::class)->detail($actor, $this->selectedCustomerId, request()->query());
    }
}
