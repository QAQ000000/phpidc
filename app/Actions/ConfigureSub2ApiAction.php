<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\SelfHosted\Sub2ApiClient;
use App\Enums\DriverType;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\UpstreamOperationBuffer;
use App\Support\AdminAuditContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConfigureSub2ApiAction
{
    /** Null actor is reserved for trusted CLI callers; web changes always supply the authenticated actor. */
    public function execute(string $name, string $url, string $key, string $reason, ?User $actor = null, ?int $accountId = null, ?bool $active = null): UpstreamApi
    {
        if (trim($name) === '' || mb_strlen($name) > 255 || trim($reason) === '' || mb_strlen($reason) > 255
            || strlen($url) > 255 || ($accountId === null && trim($key) === '') || strlen($key) > 4096 || preg_match('/[\r\n\x00]/', $key)) {
            throw new DomainException('请填写连接名称、有效地址和管理员密钥；备注不能超过 255 个字符。');
        }
        $url = (new Sub2ApiClient(new UpstreamApi(['base_url' => $url]), app(UpstreamOperationBuffer::class)))->baseUrl();

        return DB::transaction(function () use ($name, $url, $key, $reason, $actor, $accountId, $active): UpstreamApi {
            if ($actor !== null) {
                $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
                abort_if($operator === null, 403);
                Gate::forUser($operator)->authorize('upstream.view');
                Gate::forUser($operator)->authorize('upstream.configure');
            }
            $account = $accountId === null ? new UpstreamApi(['driver' => DriverType::SUB2API, 'base_url' => $url])
                : UpstreamApi::whereKey($accountId)->where('driver', DriverType::SUB2API)->lockForUpdate()->firstOrFail();
            if ($account->exists && (($account->config['mode'] ?? null) !== 'remote_api' || ($account->config['protocol'] ?? null) !== 'balance-codes-v1')) {
                throw new DomainException('此连接不属于已支持的远程余额兑换码协议，请新建连接。');
            }
            if ($account->exists && $url !== (new Sub2ApiClient($account, app(UpstreamOperationBuffer::class)))->baseUrl()) {
                throw new DomainException('已有连接的站点地址不能更换，请新增连接以保留历史订单的原站点。');
            }
            $duplicates = UpstreamApi::where('driver', DriverType::SUB2API)->where('name', trim($name));
            if ($account->exists) {
                $duplicates->whereKeyNot($account->id);
            }
            if ($duplicates->exists()) {
                throw new DomainException('已有同名 Sub2API 连接，请使用该连接的编辑入口或更换名称。');
            }
            $before = $account->exists ? $account->only(['name', 'base_url', 'is_active']) : null;
            $account->fill(['name' => trim($name), 'is_active' => $active ?? ($account->exists ? $account->is_active : true),
                'config' => ($account->config ?? []) + ['mode' => 'remote_api', 'protocol' => 'balance-codes-v1']]);
            if (trim($key) !== '') {
                $account->encrypted_secret = $key;
            }
            $account->save();
            DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                'action' => $before === null ? 'sub2api.connection_create' : 'sub2api.connection_update', 'subject_type' => UpstreamApi::class,
                'subject_id' => $account->id, 'reason' => trim($reason), 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($account->only(['name', 'base_url', 'is_active']) + ['protocol' => 'balance-codes-v1', 'secret_changed' => trim($key) !== ''], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields($actor !== null), 'created_at' => now(), 'updated_at' => now()]);

            return $account;
        });
    }
}
