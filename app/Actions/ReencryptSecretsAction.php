<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\SettingService;
use App\Support\InstallationState;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReencryptSecretsAction
{
    public function execute(string $oldKey, string $newKey, string $reason, bool $workersStopped): void
    {
        if (! app()->isDownForMaintenance() || ! $workersStopped || trim($reason) === '' || mb_strlen($reason) > 255) {
            throw new DomainException('换钥要求维护模式、停止全部 worker/调度器，并提供 1–255 字原因。');
        }
        if ($oldKey !== config('app.key')) {
            throw new DomainException('请保留当前 APP_KEY 运行换钥。');
        }
        $state = app(InstallationState::class);
        $old = $state->encrypter($oldKey);
        $new = $state->encrypter($newKey);
        $state->exclusive(function () use ($state, $oldKey, $newKey, $old, $new, $reason): void {
            $state->assertKey($oldKey, requireSeal: true);
            $fingerprint = hash('sha256', $oldKey.'|'.$newKey);
            $journal = $state->read('key-rotation.pending');
            if ($oldKey === $newKey) {
                // If the seal was published but cleanup was interrupted, the new configured key can finish cleanup.
                $marker = DB::table('system_settings')->where('group', 'security')->where('key', 'key_rotation')->value('value');
                if ($journal === [] || ! is_string($marker) || $new->decryptString($marker) !== $journal['id']) {
                    throw new DomainException('新旧密钥必须不同；只有已完成封印的换钥可恢复清理。');
                }
                $state->remove('key-rotation.pending');

                return;
            }
            if ($journal === []) {
                $journal = ['id' => (string) Str::uuid(), 'keys' => $fingerprint];
                $state->write('key-rotation.pending', $journal);
            }
            if (! hash_equals($journal['keys'], $fingerprint)) {
                throw new DomainException('存在未完成换钥，请使用同一新旧密钥恢复，禁止开始另一轮。');
            }
            // A transaction-committed sentinel resolves interruption between DB commit and lock-file publication.
            $marker = DB::table('system_settings')->where('group', 'security')->where('key', 'key_rotation')->value('value');
            $committed = false;
            if (is_string($marker)) {
                try {
                    $committed = $new->decryptString($marker) === $journal['id'];
                } catch (DecryptException) {
                    // A previous rotation's marker uses the current old key. Verify it; do not hide corrupt ciphertext.
                    $old->decryptString($marker);
                }
            }
            // Clear only this application's known ciphertext caches; never FLUSHDB on a shared Redis.
            foreach (DB::table('system_settings')->get(['group', 'key']) as $setting) {
                app(SettingService::class)->forget($setting->group.'.'.$setting->key);
            }
            if (! $committed) {
                foreach (DB::table('upstream_apis')->get(['id', 'base_url', 'username', 'encrypted_secret']) as $account) {
                    $secret = $old->decryptString($account->encrypted_secret);
                    Cache::forget('zjmf:jwt:'.hash('sha256', $account->id.'|'.$account->base_url.'|'.$account->username.'|'.$secret));
                }
                DB::transaction(function () use ($old, $new, $newKey, $journal, $reason): void {
                    // Offline operation still preserves INV-L1's row order. No balance/ledger values are changed.
                    DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
                    $this->rotateJson('invoices', 'line_items', $old, $new);
                    $this->rotateColumn('instances', 'encrypted_credentials', $old, $new);
                    $this->rotateJson('instances', 'active_config', $old, $new);
                    foreach (DB::table('redemption_codes')->orderBy('id')->lockForUpdate()->get(['id', 'code']) as $row) {
                        $plaintext = $old->decryptString($row->code);
                        DB::table('redemption_codes')->where('id', $row->id)->update([
                            'code' => $new->encryptString($plaintext), 'code_hash' => hash_hmac('sha256', $plaintext, $newKey),
                        ]);
                    }
                    $this->rotateJson('fulfillment_tasks', 'payload', $old, $new);
                    $this->rotateColumn('affiliate_withdrawals', 'encrypted_payout_details', $old, $new);
                    $this->rotateColumn('affiliate_withdrawals', 'encrypted_payout_evidence', $old, $new);
                    $this->rotateColumn('affiliate_withdrawals', 'operation_requests', $old, $new);
                    $this->rotateColumn('wallet_clearances', 'payout_details', $old, $new);
                    $this->rotateColumn('wallet_clearances', 'payout_evidence', $old, $new);
                    $this->rotateColumn('wallet_clearances', 'operation_requests', $old, $new);
                    $this->rotateColumn('reconciliation_entries', 'evidence', $old, $new);
                    $this->rotateColumn('offline_invoice_receipts', 'encrypted_evidence', $old, $new);
                    $this->rotateColumn('notification_events', 'encrypted_payload', $old, $new);
                    $this->rotateColumn('notification_deliveries', 'encrypted_payload', $old, $new);
                    $this->rotateColumn('upstream_apis', 'encrypted_secret', $old, $new);
                    foreach (DB::table('system_settings')->where('is_encrypted', true)->orderBy('id')->lockForUpdate()->get(['id', 'value']) as $row) {
                        if ($row->value !== null) {
                            DB::table('system_settings')->where('id', $row->id)->update(['value' => $new->encryptString($old->decryptString($row->value))]);
                        }
                    }
                    DB::table('system_settings')->updateOrInsert(['group' => 'security', 'key' => 'key_rotation'], [
                        'value' => $new->encryptString($journal['id']), 'is_encrypted' => true, 'updated_at' => now(), 'created_at' => now(),
                    ]);
                    DB::table('audit_logs')->insert(['actor_type' => 'system', 'actor_id' => null, 'action' => 'security.reencrypt',
                        'reason' => $reason, 'before' => '{}', 'after' => json_encode(['rotation_id' => $journal['id']], JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now()]);
                });
            }
            $state->write('installed.lock', $state->seal($state->read('installed.lock'), $newKey));
            $state->remove('key-rotation.pending');
        });
    }

    private function rotateColumn(string $table, string $column, Encrypter $old, Encrypter $new): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->lockForUpdate()->chunkById(200, function ($rows) use ($table, $column, $old, $new): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([$column => $new->encryptString($old->decryptString($row->{$column}))]);
            }
        });
    }

    private function rotateJson(string $table, string $column, Encrypter $old, Encrypter $new): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->lockForUpdate()->chunkById(200, function ($rows) use ($table, $column, $old, $new): void {
            foreach ($rows as $row) {
                $value = json_decode($row->{$column}, true, flags: JSON_THROW_ON_ERROR);
                array_walk_recursive($value, function (&$ciphertext, $key) use ($old, $new): void {
                    if (in_array($key, ['encrypted_params', 'encrypted_operation', 'encrypted_purchase', 'encrypted_result', 'encrypted_request', 'encrypted_binding'], true)) {
                        if (! is_string($ciphertext)) {
                            throw new DomainException('加密载荷格式异常，换钥事务已中止。');
                        }
                        $ciphertext = $new->encryptString($old->decryptString($ciphertext));
                    }
                });
                DB::table($table)->where('id', $row->id)->update([$column => json_encode($value, JSON_THROW_ON_ERROR)]);
            }
        });
    }
}
