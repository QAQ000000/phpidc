<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\KycInitiateResult;
use App\Data\KycStatusResult;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\Request;

interface KycDriverInterface
{
    public function initiate(User $user, array $payload): KycInitiateResult;

    public function queryStatus(KycVerification $verification): KycStatusResult;

    public function handleCallback(Request $request): KycStatusResult;
}
