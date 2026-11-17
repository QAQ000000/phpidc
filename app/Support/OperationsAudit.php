<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class OperationsAudit
{
    public static function record(User $actor, string $action, string $subject, int $id, string $reason, array $before = [], array $after = []): void
    {
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        DB::table('audit_logs')->insert(['actor_id' => $actor->id, 'actor_type' => 'user', 'action' => $action,
            'subject_type' => $subject, 'subject_id' => $id, 'reason' => $reason,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);
    }
}
