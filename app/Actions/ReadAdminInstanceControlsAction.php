<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Instance;
use App\Models\User;
use App\Support\AdminMachineAccess;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReadAdminInstanceControlsAction
{
    public function execute(User $actor, Instance $instance): array
    {
        $access = app(AdminMachineAccess::class);
        ['owner' => $owner, 'instance' => $snapshot] = $access->authorize($actor, $instance);
        abort_unless($snapshot->driver_type === DriverType::ZJMF_API, 422);
        $controls = app(ReadInstanceControlsAction::class)->execute($owner, $snapshot);

        return DB::transaction(function () use ($actor, $snapshot, $controls, $access): array {
            ['actor' => $operator, 'owner' => $owner, 'instance' => $current] = $access->lock($actor, $snapshot);
            if (! hash_equals($access->fingerprint($snapshot), $access->fingerprint($current))) {
                throw ValidationException::withMessages(['control' => '服务绑定或上游账户已变化，请刷新后重试。']);
            }
            OperationsAudit::record($operator, 'instance.machine.controls_read', Instance::class, $current->id,
                '管理员读取机器操作菜单', [], ['owner_id' => $owner->id, 'commands' => $controls['commands'] ?? []]);

            return $controls;
        });
    }
}
