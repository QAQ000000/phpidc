<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackfillBindingOrphanCasesAction
{
    public function execute(): int
    {
        $count = 0;
        foreach (InstanceUpstreamBinding::where('upstream_disposition', 'kept_orphan')->lazyById(100) as $binding) {
            $instance = Instance::findOrFail($binding->instance_id);
            DB::transaction(function () use ($binding, $instance): void {
                User::withTrashed()->whereKey($instance->user_id)->lockForUpdate()->firstOrFail();
                Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
                $original = InstanceUpstreamBinding::whereKey($binding->id)->lockForUpdate()->firstOrFail();
                app(RecordBindingOrphanCaseAction::class)->execute($original);
            });
            $count++;
        }

        return $count;
    }
}
