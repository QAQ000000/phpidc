<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Encrypted, temporary evidence conclusions; the ledger and locked user revision remain authoritative. */
class ReceivableVerificationCache
{
    public function __construct(private readonly ReceivableCheckRequests $requests) {}

    public function key(int $userId, string $revision): string
    {
        return 'receivable-verification:'.hash('sha256', json_encode([$this->keyVersion(), $this->requests->algorithm(), $userId, $revision], JSON_THROW_ON_ERROR));
    }

    public function get(User $current): ?array
    {
        if ($current->receivable_reconciliation_required) {
            return null;
        }
        try {
            $encrypted = $this->cache()->get($this->key($current->id, (string) $current->receivable_revision));
            if (! is_string($encrypted)) {
                return null;
            }
            $proof = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($proof) || ($proof['key_version'] ?? null) !== $this->keyVersion()
                || ! is_int($proof['started_at'] ?? null) || ! is_int($proof['expires_at'] ?? null)
                || $proof['started_at'] > now()->getTimestamp() || $proof['expires_at'] <= now()->getTimestamp()
                || $proof['expires_at'] <= $proof['started_at']
                || $proof['expires_at'] > $this->requests->expiresAt(CarbonImmutable::createFromTimestamp($proof['started_at']))->getTimestamp()
                || ($proof['scan']['projections'] ?? null) !== []
                || ! $this->matches($current, $proof['scan'] ?? null)) {
                return null;
            }

            return $proof;
        } catch (Throwable) {
            // Missing or unreadable temporary evidence always falls back to a complete scan.
            return null;
        }
    }

    /** The caller rereads the original user after its completed full scan before publishing. */
    public function put(User $current, array $scan, CarbonImmutable $startedAt): void
    {
        $expiresAt = $this->requests->expiresAt($startedAt);
        if ($expiresAt->isPast() || ! $this->matches($current, $scan)) {
            return;
        }
        // Captured root details and original operation input never enter the shared proof.
        $summary = array_intersect_key($scan, array_flip(['user_id', 'revision', 'reconciliation_required', 'algorithm', 'valid', 'has_outstanding', 'digest', 'totals']));
        $summary['projections'] = [];
        $proof = ['key_version' => $this->keyVersion(), 'started_at' => $startedAt->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(), 'scan' => $summary];
        $this->cache()->put($this->key($current->id, (string) $current->receivable_revision),
            Crypt::encryptString(json_encode($proof, JSON_THROW_ON_ERROR)), $expiresAt);
    }

    private function matches(User $current, mixed $scan): bool
    {
        if (! is_array($scan) || ($scan['user_id'] ?? null) !== $current->id
            || ($scan['revision'] ?? null) !== (string) $current->receivable_revision
            || ($scan['algorithm'] ?? null) !== $this->requests->algorithm()
            || ($scan['reconciliation_required'] ?? null) !== false || $current->receivable_reconciliation_required
            || ($scan['valid'] ?? null) !== true || ! is_bool($scan['has_outstanding'] ?? null)
            || ! is_string($scan['digest'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $scan['digest']) !== 1
            || ! is_array($scan['totals'] ?? null)) {
            return false;
        }
        $totals = $scan['totals'];
        if (! is_int($totals['roots'] ?? null) || $totals['roots'] < 0 || ($totals['invalid'] ?? null) !== 0) {
            return false;
        }
        foreach (['principal', 'settled', 'reversed', 'remaining'] as $column) {
            if (! is_string($totals[$column] ?? null) || preg_match('/^\d+\.\d{2}$/D', $totals[$column]) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function keyVersion(): string
    {
        return hash('sha256', (string) config('app.key'));
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('receivables.cache_store', 'redis'));
    }
}
