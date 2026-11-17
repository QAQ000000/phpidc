<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\SelfHosted\Sub2ApiClient;
use App\Enums\DriverType;
use App\Models\UpstreamApi;
use App\Services\UpstreamOperationBuffer;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConfigureSub2ApiAction
{
    public function execute(string $name, string $url, string $key, string $reason): UpstreamApi
    {
        if (trim($name) === '' || trim($reason) === '' || trim($key) === '' || preg_match('/[\r\n\x00]/', $key)) {
            throw new DomainException('A connection name, valid administrator key and operator reason are required.');
        }

        return DB::transaction(function () use ($name, $url, $key, $reason): UpstreamApi {
            $account = new UpstreamApi(['name' => $name, 'base_url' => $url, 'driver' => DriverType::SUB2API,
                'encrypted_secret' => $key, 'is_active' => true, 'config' => ['mode' => 'remote_api', 'protocol' => 'balance-codes-v1']]);
            $account->base_url = (new Sub2ApiClient($account, app(UpstreamOperationBuffer::class)))->baseUrl();
            $account->save();
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'sub2api.connection_create', 'subject_type' => UpstreamApi::class,
                'subject_id' => $account->id, 'reason' => $reason, 'after' => json_encode(['base_url' => $account->base_url, 'protocol' => 'balance-codes-v1']),
                'created_at' => now(), 'updated_at' => now()]);

            return $account;
        });
    }
}
