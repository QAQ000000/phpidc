<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

class DirectVncConnection
{
    public static function fromClassic(array $response): array
    {
        $outer = $response['data']['url'] ?? null;
        $parts = is_string($outer) ? parse_url($outer) : false;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['path'] ?? null) !== '/dcim/novnc'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\\\\\x00-\x20\x7f]/', $outer)) {
            self::unavailable();
        }
        parse_str($parts['query'] ?? '', $query);
        $socket = is_string($query['url'] ?? null) ? base64_decode($query['url'], true) : false;
        $url = is_string($socket) ? parse_url($socket) : false;
        $password = $query['password'] ?? $response['data']['pass'] ?? '';
        if (! is_string($socket) || ! is_array($url) || ($url['scheme'] ?? null) !== 'wss' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || preg_match('/[\\\\\x00-\x20\x7f]/', $socket)
            || ! is_string($password)) {
            self::unavailable();
        }

        // host_token embeds the machine password in classic; it is unnecessary for direct RFB.
        return ['url' => $socket, 'password' => $password];
    }

    private static function unavailable(): never
    {
        throw ValidationException::withMessages(['vnc' => '上游未返回已验证的安全直连 VNC 地址，请联系管理员。']);
    }
}
