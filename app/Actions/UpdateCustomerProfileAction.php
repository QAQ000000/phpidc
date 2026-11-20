<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Support\AdminCustomerAccess;
use App\Support\CustomerEmailLookup;
use App\Support\OperationsAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateCustomerProfileAction
{
    public function execute(User $target, User $actor, array $input): User
    {
        foreach (['name', 'email', 'phone', 'reason'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
            }
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower($input['email']);
        }
        if (($input['phone'] ?? null) === '') {
            $input['phone'] = null;
        }
        $data = validator($input, ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/D'], 'reason' => ['nullable', 'string', 'max:255']])->validate();
        $access = app(AdminCustomerAccess::class);
        try {
            // Same cache -> user lock order as PhoneBindingAction; an old in-flight OTP cannot overwrite the edit.
            $result = Cache::lock('phone-binding-lock:'.$target->id, 60)->get(function () use ($target, $actor, $data, $access): User {
                $contactsChanged = false;
                $updated = DB::transaction(function () use ($target, $actor, $data, $access, &$contactsChanged): User {
                    ['actor' => $operator, 'target' => $locked] = $access->lock($target, $actor, 'customers.manage');
                    if (app(CustomerEmailLookup::class)->matching($data['email'], withTrashed: true)->whereKeyNot($locked->id)->exists()) {
                        throw ValidationException::withMessages(['email' => '此邮箱已被其他账户使用。']);
                    }
                    $phone = $data['phone'] ?? null;
                    if ($phone !== null && User::withTrashed()->where('phone', $phone)->whereKeyNot($locked->id)->exists()) {
                        throw ValidationException::withMessages(['phone' => '此号码已被其他账户使用。']);
                    }
                    $changes = [];
                    foreach (['name' => $data['name'], 'email' => $data['email'], 'phone' => $phone] as $key => $value) {
                        if ($locked->getAttribute($key) !== $value) {
                            $changes[$key] = $value;
                        }
                    }
                    if ($changes === []) {
                        return $locked;
                    }
                    $emailChanged = array_key_exists('email', $changes);
                    $phoneChanged = array_key_exists('phone', $changes);
                    $contactsChanged = $emailChanged || $phoneChanged;
                    if ($emailChanged) {
                        Password::broker()->deleteToken($locked);
                        UserNotificationBinding::where('user_id', $locked->id)->whereIn('channel', ['mail', 'email'])->update(['verified_at' => null]);
                    }
                    if ($phoneChanged) {
                        UserNotificationBinding::where('user_id', $locked->id)->where('channel', 'sms')->update(['verified_at' => null]);
                    }
                    $locked->forceFill($changes + ($emailChanged ? ['email_verified_at' => null] : []));
                    if ($contactsChanged) {
                        $access->revokeSessions($locked);
                    } else {
                        $locked->save();
                    }
                    if ($emailChanged) {
                        // Do not let a stale token for a previously released new address become usable.
                        Password::broker()->deleteToken($locked);
                    }
                    OperationsAudit::record($operator, 'customer.profile_updated', User::class, $locked->id,
                        trim($data['reason'] ?? '') ?: '更新客户基础资料', [], ['changed_keys' => array_keys($changes),
                            'email_verification_invalidated' => $emailChanged, 'sms_verification_invalidated' => $phoneChanged,
                            'sessions_revoked' => $contactsChanged, 'auth_revision' => $locked->auth_revision]);

                    return $locked;
                });
                if ($contactsChanged) {
                    Cache::forget('phone-binding:'.$target->id);
                }

                return $updated;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['operation' => '邮箱或手机号已被其他账户使用，请刷新后核对。']);
        }
        if (! $result instanceof User) {
            throw ValidationException::withMessages(['operation' => '客户手机号验证或资料变更正在处理中，请稍后重试。']);
        }

        return $result;
    }
}
