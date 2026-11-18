<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\BotDriverInterface;
use App\Services\SettingService;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class TelegramBotDriver implements BotDriverInterface
{
    public function __construct(private readonly SettingService $settings) {}

    public function send(string $target, string $markdown): bool
    {
        $token = $this->settings->get('telegram.bot_token', '');
        if ($token === '') {
            return false;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()->asForm()->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $target, 'text' => $markdown]);
        } catch (ConnectionException) {
            throw new UncertainNotificationException;
        }
        if ($response->json('ok') === false && $response->json('error_code') === 429) {
            throw new RetryableNotificationException(max(1, (int) $response->json('parameters.retry_after', 60)));
        }
        if ($response->serverError() || ! is_bool($response->json('ok'))) {
            throw new UncertainNotificationException;
        }

        return $response->successful() && $response->json('ok') === true;
    }
}
