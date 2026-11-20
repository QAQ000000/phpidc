<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Support\AdminCustomerAccess;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class SetCustomerAccessAction
{
    public function execute(User $target, User $actor, bool $disabled, ?string $reason = null): User
    {
        validator(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:255']])->validate();
        $result = Cache::lock('phone-binding-lock:'.$target->id, 60)->get(function () use ($target, $actor, $disabled, $reason): User {
            $changed = false;
            $updated = DB::transaction(function () use ($target, $actor, $disabled, $reason, &$changed): User {
                $access = app(AdminCustomerAccess::class);
                ['actor' => $operator, 'target' => $locked] = $access->lock($target, $actor, 'customers.status.manage');
                $wasDisabled = $locked->disabled_at !== null;
                if ($wasDisabled === $disabled) {
                    return $locked;
                }
                $changed = true;
                $locked->disabled_at = $disabled ? now() : null;
                if ($disabled) {
                    Password::broker()->deleteToken($locked);
                    $access->revokeSessions($locked);
                } else {
                    $locked->save();
                }
                OperationsAudit::record($operator, $disabled ? 'customer.access_disabled' : 'customer.access_restored', User::class, $locked->id,
                    trim($reason ?? '') ?: ($disabled ? '停用客户账户访问' : '恢复客户账户访问'), ['disabled' => $wasDisabled],
                    ['disabled' => $disabled, 'auth_revision' => $locked->auth_revision, 'sessions_revoked' => $disabled]);

                return $locked;
            });
            if ($disabled && $changed) {
                Cache::forget('phone-binding:'.$target->id);
            }

            return $updated;
        });
        if (! $result instanceof User) {
            throw ValidationException::withMessages(['operation' => '客户手机号验证或账户变更正在处理中，请稍后重试。']);
        }

        return $result;
    }
}
