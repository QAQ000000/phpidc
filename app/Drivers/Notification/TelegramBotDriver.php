<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\BotDriverInterface;
use App\Services\SettingService;
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
        $response = Http::timeout(30)->withoutRedirecting()->asForm()->post('https://api.telegram.org/bot'.$token.'/sendMessage', ['chat_id' => $target, 'text' => $markdown]);

        return $response->successful() && $response->json('ok') === true;
    }
}
