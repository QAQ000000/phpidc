<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\KycInitiateResult;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;

class QueryKycAction
{
    public function authorization(User $user, KycVerification $verification): KycInitiateResult
    {
        $this->requireOwner($user, $verification);
        if ($verification->status !== 'pending') {
            throw new DomainException('此申请已结束，请查看账户当前认证状态。');
        }

        return app(DriverManager::class)->kyc()->authorization($verification);
    }

    public function refresh(User $user, KycVerification $verification): KycVerification
    {
        $this->requireOwner($user, $verification);
        if ($verification->status !== 'pending') {
            return $verification;
        }
        $driver = app(DriverManager::class)->kyc();
        $result = $driver->queryStatus($verification);
        app(ApplyKycStatusAction::class)->execute($driver::IDENTIFIER, $result);

        return $verification->fresh() ?? throw new DomainException('实名申请记录不可用。');
    }

    private function requireOwner(User $user, KycVerification $verification): void
    {
        abort_unless($verification->user_id === $user->id, 403);
        if (KycVerification::where('user_id', $user->id)->latest('id')->value('id') !== $verification->id) {
            throw new DomainException('请查询最近一次实名申请。');
        }
    }
}
