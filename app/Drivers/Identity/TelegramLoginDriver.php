<?php

declare(strict_types=1);

namespace App\Drivers\Identity;

use App\Services\SettingService;
use DomainException;
use Illuminate\Http\Request;

class TelegramLoginDriver
{
    public function __construct(private readonly SettingService $settings) {}

    public function ready(): bool
    {
        return $this->settings->getBool('oauth.telegram_enabled')
            && preg_match('/^[0-9]+:[A-Za-z0-9_-]{30,}$/D', $this->settings->get('telegram.bot_token', '') ?? '') === 1
            && preg_match('/^[A-Za-z0-9_]{2,29}[Bb][Oo][Tt]$/D', $this->username()) === 1;
    }

    public function username(): string
    {
        return $this->settings->get('telegram.bot_username', '') ?? '';
    }

    public function subject(Request $request): string
    {
        if (! $this->ready()) {
            throw new DomainException('Telegram 登录尚未配置或未启用。');
        }
        // Verify original values: TrimStrings middleware must not change signed names.
        parse_str((string) $request->server('QUERY_STRING'), $data);
        unset($data['state']); // Local session state is checked by the Action; Telegram does not sign it.
        $allowed = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'];
        if (array_diff(array_keys($data), $allowed) !== [] || count(array_filter($data, 'is_string')) !== count($data)
            || preg_match('/^[1-9][0-9]{0,19}$/D', $data['id'] ?? '') !== 1
            || preg_match('/^[0-9]{10}$/D', $data['auth_date'] ?? '') !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $data['hash'] ?? '') !== 1
            || strlen(implode('', $data)) > 4096) {
            throw new DomainException('Telegram 授权数据无效。');
        }
        $date = (int) $data['auth_date'];
        if ($date < now()->timestamp - 300 || $date > now()->timestamp + 30) {
            throw new DomainException('Telegram 授权已过期，请重新发起。');
        }
        $signature = $data['hash'];
        unset($data['hash']);
        ksort($data, SORT_STRING);
        $lines = [];
        foreach ($data as $key => $value) {
            $lines[] = $key.'='.$value;
        }
        $secret = hash('sha256', (string) $this->settings->get('telegram.bot_token'), true);
        if (! hash_equals(hash_hmac('sha256', implode("\n", $lines), $secret), $signature)) {
            throw new DomainException('Telegram 授权签名无效。');
        }

        return $data['id'];
    }
}
