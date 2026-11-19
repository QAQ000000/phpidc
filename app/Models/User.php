<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && ! $this->trashed() && collect([
            'promotion.view', 'affiliate.view', 'support.view', 'notification.view', 'download.view',
            'inventory.view', 'upstream.view', 'cms.view', 'finance.view', 'tasks.view', 'audit.view', 'access.view', 'settings.view',
        ])->contains(fn (string $permission): bool => $this->can($permission));
    }

    /**
     * Decimal casts return two-place strings, including after SQLite reloads
     * (INV-M1). Business arithmetic remains in Money.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'kyc_verified_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'password' => 'hashed',
            'credit' => 'decimal:2',
            'frozen_credit' => 'decimal:2',
            'wallet_clearance_frozen' => 'decimal:2',
            'receivable_revision' => 'integer',
            'receivable_reconciliation_required' => 'boolean',
            'affiliate_balance' => 'decimal:2',
            'affiliate_frozen_balance' => 'decimal:2',
        ];
    }
}
