<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class ReceivableSnapshot
{
    public function read(int $userId, int $seconds, array $captureIds = []): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Receivable scans must start before any MySQL financial transaction.');
            }
            // SET TRANSACTION affects this transaction only, including deployments configured READ COMMITTED.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($userId, $seconds, $captureIds): array {
            $user = User::withTrashed()->whereKey($userId)->firstOrFail();
            $result = app(AffiliateReceivableBalance::class)->scanUser($userId, $seconds, $captureIds);

            return ['user_id' => $userId, 'revision' => (string) $user->receivable_revision,
                'reconciliation_required' => (bool) $user->receivable_reconciliation_required,
                'algorithm' => app(ReceivableCheckRequests::class)->algorithm(), ...$result];
        });
    }
}
