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
    public function execute(string $name, string $url, string $username, string $secret, string $reason, bool $rawJwt = false, bool $creditLimit = false, ?User $actor = null): UpstreamApi
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('upstream.configure');
        }
        if (trim($name) === '' || trim($username) === '' || trim($secret) === '' || trim($reason) === ''
            || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DomainException('A name, URL, upstream username, API secret and reason are required.');
        }

        return DB::transaction(function () use ($name, $url, $username, $secret, $reason, $rawJwt, $creditLimit, $actor): UpstreamApi {
            $account = UpstreamApi::updateOrCreate(['name' => $name, 'driver' => DriverType::ZJMF_API],
                ['base_url' => rtrim($url, '/'), 'username' => $username, 'encrypted_secret' => $secret,
                    'auth_header_mode' => $rawJwt ? 'raw_jwt' : 'bearer', 'config' => ['allow_credit_limit' => $creditLimit], 'is_active' => true]);
            DB::table('audit_logs')->insert(['actor_type' => $actor ? 'user' : 'system', 'actor_id' => $actor?->id, 'action' => 'upstream.classic_configure', 'subject_type' => UpstreamApi::class,
                'subject_id' => $account->id, 'reason' => $reason, 'after' => json_encode(['base_url' => $url, 'secret' => '[REDACTED]',
                    'auth_header_mode' => $account->auth_header_mode, 'allow_credit_limit' => $creditLimit]), 'created_at' => now(), 'updated_at' => now()]);

            return $account;
        });
    }
}
