<?php

declare(strict_types=1);

namespace App\Contracts;

interface SmsDriverInterface
{
    public function sendTemplate(string $phone, string $templateCode, array $params): bool;
}
