<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\RedemptionCode;
use Illuminate\Support\Facades\DB;

class ReapRedemptionReservationsAction
{
    public function execute(): void
    {
        RedemptionCode::where('status', 'reserved')->where('reserved_until', '<', now())->select('id')
            ->chunkById(100, function ($codes): void {
                foreach ($codes as $code) {
                    $this->executeOne($code->id);
                }
            });
    }

    public function executeOne(int $id): void
    {
        $candidate = RedemptionCode::find($id);
        if ($candidate === null) {
            return;
        }
        DB::transaction(function () use ($candidate): void {
            $instance = $candidate->instance_id === null ? null : Instance::whereKey($candidate->instance_id)->lockForUpdate()->first();
            $code = RedemptionCode::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($code->instance_id !== $candidate->instance_id || $code->status !== 'reserved' || $code->reserved_until === null
                || $code->reserved_until->isFuture() || $code->sold_at !== null || ($instance !== null && $instance->encrypted_credentials !== null)) {
                return;
            }
            $code->update(['status' => 'available', 'instance_id' => null, 'reserved_until' => null]);
        });
    }
}
