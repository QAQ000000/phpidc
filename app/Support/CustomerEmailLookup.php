<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerEmailLookup
{
    /** @return Builder<User> */
    public function matching(string $email, bool $withTrashed = false): Builder
    {
        $query = $withTrashed ? User::withTrashed() : User::query();
        $normalized = Str::lower(trim($email));

        // Production uses the existing MySQL case-insensitive unique email index.
        // SQLite's binary unique index still needs the legacy ambiguity check.
        return DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'mysql'
            ? $query->where('email', $normalized)
            : $query->whereRaw('LOWER(email) = ?', [$normalized]);
    }

    public function lockUnique(string $email): ?User
    {
        $matches = $this->matching($email)->limit(2)->get(['id']);
        if ($matches->count() !== 1) {
            return null;
        }

        // Lock only this identity and recheck its current email/deletion state.
        return $this->matching($email)->whereKey($matches->first()->id)->lockForUpdate()->first();
    }
}
