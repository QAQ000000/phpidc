<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\KycStatusResult;
use App\Models\KycVerification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApplyKycStatusAction
{
    /** Called only with a result from a verified driver, never directly from request fields. */
    public function execute(string $driver, KycStatusResult $result): bool
    {
        $summary = $result->maskedSummary;
        if (array_diff(array_keys($summary), ['name', 'id_last4']) !== []
            || (isset($summary['name']) && (! is_string($summary['name']) || ! str_contains($summary['name'], '*') || ! preg_match('/^[\p{L}*·]{1,20}$/uD', $summary['name'])))
            || (isset($summary['id_last4']) && (! is_string($summary['id_last4']) || ! preg_match('/^[0-9]{3}[0-9Xx]$/D', $summary['id_last4'])))) {
            throw new DomainException('KYC result contains an invalid masked summary.');
        }
        $matches = KycVerification::where('driver', $driver)->where('external_id', $result->externalId)->get(['id', 'user_id']);
        if ($matches->count() !== 1) {
            throw new DomainException('KYC application cannot be uniquely matched.');
        }
        $match = $matches->firstOrFail();

        return DB::transaction(function () use ($match, $driver, $result, $summary): bool {
            $user = User::whereKey($match->user_id)->lockForUpdate()->firstOrFail();
            $record = KycVerification::whereKey($match->id)->where('driver', $driver)->where('external_id', $result->externalId)->lockForUpdate()->firstOrFail();
            $latest = KycVerification::where('user_id', $user->id)->latest('id')->firstOrFail();
            if ($latest->id !== $record->id || $record->status === $result->status) {
                return false;
            }
            if ($record->status !== 'pending') {
                throw new DomainException('Contradictory terminal KYC result requires manual reconciliation.');
            }
            $record->status = $result->status;
            $record->masked_summary = $summary;
            $record->verified_at = $result->status === 'verified' ? now() : null;
            $record->save();
            $user->kyc_verified_at = $record->verified_at;
            $user->save();
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'identity.kyc_status',
                'subject_type' => KycVerification::class, 'subject_id' => $record->id, 'reason' => 'Authenticated KYC result',
                'after' => json_encode(['status' => $result->status]), 'created_at' => now(), 'updated_at' => now()]);

            return true;
        });
    }
}
