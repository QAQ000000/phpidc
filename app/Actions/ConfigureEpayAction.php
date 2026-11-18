<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PaymentAttempt;
use App\Services\SettingService;
use App\Support\EpayProfiles;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConfigureEpayAction
{
    public function execute(string $url, string $pid, string $key, string $reason): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || trim($pid) === '' || trim($key) === '' || trim($reason) === '') {
            throw new DomainException('A gateway URL, merchant ID, key and operator reason are required.');
        }
        DB::transaction(function () use ($url, $pid, $key, $reason): void {
            $this->lockConfiguration();
            $previous = $this->captureCurrentProfile();
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
            foreach (['epay_api_url' => $credentials['api_url'], 'epay_pid' => $pid, 'epay_key' => $key,
                EpayProfiles::CURRENT_KEY => $profile['id']] as $name => $value) {
                DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => $name], [
                    'value' => $name === 'epay_key' ? Crypt::encryptString($value) : $value,
                    'is_encrypted' => $name === 'epay_key', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.$name));
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'payment.epay_configure', 'reason' => $reason,
                'after' => json_encode(['api_url' => $credentials['api_url'], 'pid' => $pid, 'key' => '[REDACTED]',
                    'profile_id' => $profile['id']], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
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
