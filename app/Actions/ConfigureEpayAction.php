<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PaymentAttempt;
use App\Models\User;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\EpayProfiles;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ConfigureEpayAction
{
    /** Change admission flags without creating or rewriting merchant snapshots. */
    public function setChannels(User $actor, bool $enabled, ?string $reason = null): void
    {
        $reason = trim($reason ?? '');
        $reason = $reason === '' ? ($enabled ? '启用易支付插件' : '停用易支付插件') : $reason;
        validator(['reason' => $reason], ['reason' => ['required', 'string', 'max:255']])->validate();
        DB::transaction(function () use ($actor, $enabled, $reason): void {
            $this->lockConfiguration();
            $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            if ($operator === null) {
                throw new AuthorizationException('管理员账户已停用。');
            }
            Gate::forUser($operator)->authorize('settings.view');
            Gate::forUser($operator)->authorize('settings.payment.manage');
            if ($enabled) {
                $profiles = app(EpayProfiles::class);
                $credentials = $profiles->credentials();
                if (! filter_var($credentials['api_url'], FILTER_VALIDATE_URL)
                    || ! in_array(parse_url($credentials['api_url'], PHP_URL_SCHEME), ['http', 'https'], true)
                    || trim($credentials['pid']) === '' || trim($credentials['key']) === '') {
                    throw new DomainException('启用易支付前请先保存完整商户配置。');
                }
                $id = $profiles->currentId();
                if ($id !== null && array_diff_assoc($credentials, $profiles->find($id)) !== []) {
                    throw new DomainException('商户配置与历史快照不一致，请先修复配置。');
                }
            }
            $keys = [];
            foreach (EpayProfiles::GATEWAYS as $gateway) {
                $name = $gateway.'_enabled';
                DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => $name], [
                    'value' => $enabled ? '1' : '0', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $keys[] = 'payment.'.$name;
                DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.$name));
            }
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id,
                'action' => 'payment.epay_channels', 'subject_type' => 'system_settings', 'reason' => $reason,
                'after' => json_encode(['keys' => $keys], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** @param array<string, mixed>|null $channels Raw channel input, restricted to boolean flags below. */
    public function execute(string $url, string $pid, string $key, string $reason, ?User $actor = null, ?array $channels = null): void
    {
        $preserveKey = $actor !== null && trim($key) === '';
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || trim($pid) === '' || (! $preserveKey && trim($key) === '') || trim($reason) === ''
            || ($actor !== null && mb_strlen($reason) > 255)) {
            throw new DomainException('A gateway URL, merchant ID, key and operator reason are required.');
        }
        if ($channels !== null && (array_diff(array_keys($channels), EpayProfiles::GATEWAYS) !== []
            || array_filter($channels, fn ($enabled): bool => ! is_bool($enabled)) !== [])) {
            throw new DomainException('Choose supported payment channels with boolean enablement.');
        }
        DB::transaction(function () use ($url, $pid, $key, $reason, $actor, $channels, $preserveKey): void {
            $this->lockConfiguration();
            if ($actor !== null) {
                $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
                if ($operator === null) {
                    throw new AuthorizationException('The operator account is no longer active.');
                }
                Gate::forUser($operator)->authorize('settings.view');
                Gate::forUser($operator)->authorize('settings.payment.manage');
            }
            $previous = $this->captureCurrentProfile();
            if ($preserveKey) {
                $key = $previous['key'] ?? app(EpayProfiles::class)->credentials()['key'];
                if (trim($key) === '') {
                    throw new DomainException('A merchant key is required for the first payment configuration.');
                }
            }
            $credentials = ['api_url' => rtrim($url, '/'), 'pid' => $pid, 'key' => $key];
            $changed = $previous === null || array_diff_assoc($credentials, $previous) !== [];
            if ($changed && $previous === null && $this->legacyAttempts()->exists()) {
                throw new DomainException('Existing payment attempts require the previous complete merchant configuration before it can be changed.');
            }
            if ($changed && $previous !== null) {
                // Include failed/expired attempts: a gateway can still deliver their real late payment.
                $this->legacyAttempts()->lockForUpdate()->chunkById(200, function ($attempts) use ($previous): void {
                    foreach ($attempts as $attempt) {
                        $payload = $attempt->request_payload ?? [];
                        $payload[EpayProfiles::ATTEMPT_KEY] = $previous['id'];
                        $attempt->update(['request_payload' => $payload]);
                    }
                });
            }
            $profile = $changed ? $this->createProfile($credentials) : $previous;
            $keys = [];
            foreach (['epay_api_url' => $credentials['api_url'], 'epay_pid' => $pid, 'epay_key' => $key,
                EpayProfiles::CURRENT_KEY => $profile['id']] as $name => $value) {
                if ($name === 'epay_key' && $preserveKey) {
                    continue;
                }
                DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => $name], [
                    'value' => $name === 'epay_key' ? Crypt::encryptString($value) : $value,
                    'is_encrypted' => $name === 'epay_key', 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($name !== EpayProfiles::CURRENT_KEY) {
                    $keys[] = 'payment.'.$name;
                }
                DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.$name));
            }
            foreach ($channels ?? [] as $gateway => $enabled) {
                $name = $gateway.'_enabled';
                DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => $name], [
                    'value' => $enabled ? '1' : '0', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $keys[] = 'payment.'.$name;
                DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.$name));
            }
            DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                'action' => 'payment.epay_configure', 'subject_type' => 'system_settings', 'reason' => $reason,
                'after' => json_encode(['keys' => $keys, 'profile_id' => $profile['id']], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields($actor !== null), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    /** Configuration, attempt creation and callback resolution take this metadata lock first. */
    public function lockConfiguration(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment configuration selection requires a transaction.');
        }
        // Upsert obtains an exclusive duplicate-key lock. INSERT IGNORE can leave
        // concurrent callers upgrading shared locks and deadlocking on MySQL.
        DB::table('system_settings')->upsert([['group' => 'payment', 'key' => EpayProfiles::LOCK_KEY,
            'value' => '1', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]], ['group', 'key'], ['value']);
        DB::table('system_settings')->where('group', 'payment')->where('key', EpayProfiles::LOCK_KEY)->lockForUpdate()->first();
    }

    /** @return array{id: string, api_url: string, pid: string, key: string}|null */
    public function captureCurrentProfile(): ?array
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment configuration selection requires a transaction.');
        }
        $profiles = app(EpayProfiles::class);
        $credentials = $profiles->credentials();
        $id = $profiles->currentId();
        if ($id !== null) {
            $profile = $profiles->find($id);
            if (array_diff_assoc($credentials, $profile) !== []) {
                throw new DomainException('Merchant settings differ from their recorded profile; restore them before configuring payment.');
            }

            return $profile;
        }
        if (in_array('', $credentials, true)) {
            return null;
        }
        $profile = $this->createProfile($credentials);
        DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => EpayProfiles::CURRENT_KEY], [
            'value' => $profile['id'], 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.EpayProfiles::CURRENT_KEY));

        return $profile;
    }

    /** @param array{api_url: string, pid: string, key: string} $credentials
     * @return array{id: string, api_url: string, pid: string, key: string}
     */
    private function createProfile(array $credentials): array
    {
        $id = (string) Str::ulid();
        DB::table('system_settings')->insert(['group' => 'payment', 'key' => EpayProfiles::PROFILE_PREFIX.$id,
            'value' => Crypt::encryptString(json_encode(['version' => 1, ...$credentials], JSON_THROW_ON_ERROR)),
            'is_encrypted' => true, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, ...$credentials];
    }

    private function legacyAttempts(): Builder
    {
        return PaymentAttempt::whereIn('gateway', EpayProfiles::GATEWAYS)->where(fn ($query) => $query
            ->whereNull('request_payload')->orWhereNull('request_payload->'.EpayProfiles::ATTEMPT_KEY));
    }
}
