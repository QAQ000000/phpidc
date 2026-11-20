<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\CustomerEmailLookup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticateCustomerAction
{
    public function execute(string $email, string $password, bool $remember = false): void
    {
        // Legacy mixed-case addresses remain usable without merging ambiguous identities.
        $matches = app(CustomerEmailLookup::class)->matching($email)->limit(2)->get(['id']);
        if ($matches->count() !== 1 || ! Auth::attempt(['id' => $matches->first()->id, 'password' => $password,
            'disabled_at' => null, 'anonymized_at' => null], $remember)) {
            throw ValidationException::withMessages(['email' => '邮箱或密码错误。']);
        }
    }
}
