<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Support\CustomerEmailLookup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisterCustomerAction
{
    public function execute(string $name, string $email, string $password, ?int $referrerId = null): User
    {
        $data = validator(['name' => trim($name), 'email' => Str::lower(trim($email)), 'password' => $password], [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
        ])->validate();
        try {
            return DB::transaction(function () use ($data, $referrerId): User {
                if (app(CustomerEmailLookup::class)->matching($data['email'], withTrashed: true)->exists()) {
                    throw ValidationException::withMessages(['email' => '此邮箱不可用于注册，请尝试登录或找回密码。']);
                }
                $referrer = $referrerId === null ? null : User::whereKey($referrerId)->lockForUpdate()->first();
                $user = User::create($data);
                if ($referrer !== null && $referrer->id !== $user->id) {
                    $user->forceFill(['referred_by' => $referrer->id])->save();
                }
                DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $user->id, 'action' => 'identity.register',
                    'subject_type' => User::class, 'subject_id' => $user->id, 'reason' => 'Customer registered an account',
                    'created_at' => now(), 'updated_at' => now()]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => '此邮箱不可用于注册，请尝试登录或找回密码。']);
        }
    }
}
