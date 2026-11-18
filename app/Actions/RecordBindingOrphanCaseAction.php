<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Services\BindingReconciliationCases;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordBindingOrphanCaseAction
{
    public function execute(InstanceUpstreamBinding $binding): ReconciliationCase
    {
        if (DB::transactionLevel() === 0 || $binding->upstream_disposition !== 'kept_orphan' || $binding->unbound_at === null) {
            throw new LogicException('An original unbound orphan and its owner transaction are required.');
        }
        $instance = Instance::findOrFail($binding->instance_id);
        $proof = app(BindingReconciliationCases::class);
        $existing = ReconciliationCase::where('instance_upstream_binding_id', $binding->id)->lockForUpdate()->first();
        if ($existing !== null) {
            $proof->assertSource($existing);

            return $existing;
        }
        $case = ReconciliationCase::create(['user_id' => $instance->user_id, 'instance_upstream_binding_id' => $binding->id,
            'kind' => 'binding_orphan', 'source_type' => 'instance_binding', 'source_id' => (string) $binding->id,
            'source_fingerprint' => $proof->fingerprint($proof->snapshot($binding, $instance)), 'initial_reason' => 'kept_orphan', 'status' => 'open']);
        ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => 1, 'request_key' => 'created', 'action' => 'created',
            'reason' => 'Original upstream resource retained at rebinding; disposition requires verification.',
            'evidence' => ['version' => 1, 'source_fingerprint' => $case->source_fingerprint, 'binding_id' => $binding->id]]);

        return $case;
    }
}
