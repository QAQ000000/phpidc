<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\SettingService;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ConfigureEpayAction
{
    public function execute(string $url, string $pid, string $key, string $reason): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            || trim($pid) === '' || trim($key) === '' || trim($reason) === '') {
            throw new DomainException('A gateway URL, merchant ID, key and operator reason are required.');
        }
        DB::transaction(function () use ($url, $pid, $key, $reason): void {
            foreach (['epay_api_url' => rtrim($url, '/'), 'epay_pid' => $pid, 'epay_key' => $key] as $name => $value) {
                DB::table('system_settings')->updateOrInsert(['group' => 'payment', 'key' => $name], [
                    'value' => $name === 'epay_key' ? Crypt::encryptString($value) : $value,
                    'is_encrypted' => $name === 'epay_key', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::afterCommit(fn () => app(SettingService::class)->forget('payment.'.$name));
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'payment.epay_configure', 'reason' => $reason,
                'after' => json_encode(['api_url' => $url, 'pid' => $pid, 'key' => '[REDACTED]']), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
