<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\UpstreamApi;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConfigureClassicUpstreamAction
{
    public function execute(string $name, string $url, string $username, string $secret, string $reason, ?bool $rawJwt = false, ?bool $creditLimit = false, ?User $actor = null, ?int $accountId = null): UpstreamApi
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('upstream.configure');
        }
        if (trim($name) === '' || trim($username) === '' || ($accountId === null && trim($secret) === '') || trim($reason) === ''
            || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DomainException('A name, URL, upstream username, API secret and reason are required.');
        }

        return DB::transaction(function () use ($name, $url, $username, $secret, $reason, $rawJwt, $creditLimit, $actor, $accountId): UpstreamApi {
            if ($accountId !== null) {
                $account = UpstreamApi::whereKey($accountId)->where('driver', DriverType::ZJMF_API)->lockForUpdate()->firstOrFail();
                if (UpstreamApi::where('name', $name)->where('driver', DriverType::ZJMF_API)->whereKeyNot($account->id)->exists()) {
                    throw new DomainException('已有同名连接，请使用不同名称。');
                }
            } else {
                $named = UpstreamApi::where('name', $name)->where('driver', DriverType::ZJMF_API)->lockForUpdate()->first();
                if ($actor !== null && $named !== null) {
                    throw new DomainException('已有同名连接，请使用该连接的编辑入口。');
                }
                // Existing trusted CLI integrations retain their explicit name-based update contract.
                $account = $named ?? new UpstreamApi(['name' => $name, 'driver' => DriverType::ZJMF_API]);
            }
            $before = $account->exists ? $account->only(['name', 'base_url', 'username', 'auth_header_mode', 'is_active']) : null;
            $config = $account->config ?? [];
            $rawJwt ??= $account->auth_header_mode === 'raw_jwt';
            $creditLimit ??= (bool) ($config['allow_credit_limit'] ?? false);
            $config['allow_credit_limit'] = $creditLimit;
            $account->fill(['name' => $name, 'base_url' => rtrim($url, '/'), 'username' => $username,
                'auth_header_mode' => $rawJwt ? 'raw_jwt' : 'bearer', 'config' => $config]);
            if (trim($secret) !== '') {
                $account->encrypted_secret = $secret;
            }
            if ($accountId === null) {
                $account->is_active = true;
            }
            $account->save();
            DB::table('audit_logs')->insert(['actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id, 'action' => 'upstream.classic_configure', 'subject_type' => UpstreamApi::class,
                'subject_id' => $account->id, 'reason' => $reason, 'before' => json_encode($before), 'after' => json_encode(['base_url' => $url, 'secret' => '[REDACTED]',
                    'auth_header_mode' => $account->auth_header_mode, 'allow_credit_limit' => $creditLimit]), 'created_at' => now(), 'updated_at' => now()]);

            return $account;
        });
    }
}
