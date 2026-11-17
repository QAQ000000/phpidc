<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\KycVerification;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InitiateKycAction
{
    public function execute(User $user, array $payload): KycVerification
    {
        $data = validator($payload, ['real_name' => ['required', 'string', 'max:50', 'regex:/^[\p{L}· ]+$/uD'],
            'id_number' => ['required', 'string', 'regex:/^[1-9][0-9]{16}[0-9Xx]$/D'], 'consent' => ['accepted'],
            'current_password' => ['required', 'string']])->validate();
        $driver = app(DriverManager::class)->kyc();
        if (! $driver->ready()) {
            throw new DomainException('实名认证服务尚未启用或配置不完整。');
        }
        $record = DB::transaction(function () use ($user, $data, $driver): KycVerification {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->email_verified_at === null || ! Hash::check($data['current_password'], $locked->getAuthPassword())) {
                throw ValidationException::withMessages(['operation' => '请验证邮箱并输入当前账户密码。']);
            }
            if ($locked->kyc_verified_at !== null || KycVerification::where('user_id', $locked->id)->where('status', 'pending')->exists()) {
                throw new DomainException('已有通过或待核对的实名申请，请查询原申请或联系管理员。');
            }
            $record = KycVerification::create(['user_id' => $locked->id, 'driver' => $driver::IDENTIFIER, 'status' => 'pending',
                'masked_summary' => ['name' => '*'.mb_substr(trim($data['real_name']), -1), 'id_last4' => strtoupper(substr($data['id_number'], -4))]]);
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $locked->id, 'action' => 'identity.kyc_initiate',
                'subject_type' => KycVerification::class, 'subject_id' => $record->id, 'reason' => 'Customer consented to personal identity verification',
                'created_at' => now(), 'updated_at' => now()]);

            return $record;
        });
        $result = $driver->initiate($user, ['real_name' => trim($data['real_name']), 'id_number' => strtoupper($data['id_number']),
            'request_id' => 'cms_kyc_'.$record->id.'_'.Str::ulid()]);

        return DB::transaction(function () use ($user, $record, $driver, $result): KycVerification {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $pending = KycVerification::whereKey($record->id)->where('user_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($pending->status !== 'pending' || $pending->external_id !== null || $pending->driver !== $driver::IDENTIFIER
                || $result->status !== 'pending' || $result->externalId === ''
                || KycVerification::where('driver', $driver::IDENTIFIER)->where('external_id', $result->externalId)->whereKeyNot($pending->id)->exists()
                || KycVerification::where('user_id', $locked->id)->latest('id')->value('id') !== $pending->id) {
                throw new DomainException('实名申请关联已改变，请联系管理员核对外部记录。');
            }
            $pending->external_id = $result->externalId;
            $pending->save();

            return $pending;
        });
    }
}
