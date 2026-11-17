<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterCustomerAction
{
    public function execute(string $name, string $email, string $password): User
    {
        return DB::transaction(fn () => User::create(['name' => $name, 'email' => $email, 'password' => $password]));
    }
}
