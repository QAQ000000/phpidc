<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConfigureWalletTopupAction
{
    public function execute(User $actor, bool $enabled, string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Supply a reason of 1–255 characters.']);
        }
        DB::transaction(function () use ($actor, $enabled, $reason): void {
            $this->lockConfiguration();
            $locked = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            abort_if($locked === null, 403);
            Gate::forUser($locked)->authorize('finance.view');
            Gate::forUser($locked)->authorize('finance.wallet.configure');
            $before = $this->enabled();
            DB::table('system_settings')->updateOrInsert(['group' => 'wallet', 'key' => 'topup_enabled'],
                ['value' => $enabled ? '1' : '0', 'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $locked->id,
                'action' => 'wallet.topup.configure', 'subject_type' => 'system_settings', 'reason' => trim($reason),
                'before' => json_encode(['enabled' => $before], JSON_THROW_ON_ERROR),
                'after' => json_encode(['enabled' => $enabled, 'currency' => 'CNY', 'minimum' => '1.00', 'maximum' => '10000.00'], JSON_THROW_ON_ERROR),
                ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);
            DB::afterCommit(fn () => app(SettingService::class)->forget('wallet.topup_enabled'));
        });
    }

    /** Global configuration metadata precedes every user/invoice lock for this feature. */
    public function lockConfiguration(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Top-up configuration locking requires a transaction.');
        }
        DB::table('system_settings')->upsert([['group' => 'wallet', 'key' => 'topup_lock', 'value' => '1',
            'is_encrypted' => false, 'created_at' => now(), 'updated_at' => now()]], ['group', 'key'], ['value']);
        DB::table('system_settings')->where('group', 'wallet')->where('key', 'topup_lock')->lockForUpdate()->first();
    }

    /** Read the database, never a potentially stale worker/shared settings cache. */
    public function enabled(): bool
    {
        $row = DB::table('system_settings')->where('group', 'wallet')->where('key', 'topup_enabled')->first(['value', 'is_encrypted']);

        return $row !== null && ! $row->is_encrypted && $row->value === '1';
    }
}
