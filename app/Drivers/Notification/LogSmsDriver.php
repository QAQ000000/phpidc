<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\SmsDriverInterface;
use Illuminate\Support\Facades\Log;

class LogSmsDriver implements SmsDriverInterface
{
    public function sendTemplate(string $phone, string $templateCode, array $params): bool
    {
        Log::info('SMS log transport accepted notification.', ['template' => $templateCode]);

        return true;
    }
}
