<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\AuditReceivableEvidenceJob;
use App\Models\User;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceLockWait;
use App\Support\MaintenanceState;
use Illuminate\Console\Command;
use LogicException;

class AuditReceivablesCommand extends Command
{
    protected $signature = 'app:receivables-audit';

    protected $description = 'Dispatch a bounded independent audit of complete receivable evidence per user';

    public function handle(FairMaintenanceBatch $batch, MaintenanceState $state, MaintenanceLockWait $lockWait): int
    {
        if (config('queue.connections.'.config('queue.default').'.driver') === 'sync') {
            throw new LogicException('Receivable evidence audit requires its asynchronous single-worker queue.');
        }
        $users = User::withTrashed()->where(function ($users): void {
            $users->where('receivable_reconciliation_required', true)
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('financial_transactions')->whereColumn('financial_transactions.user_id', 'users.id')
                    ->whereIn('type', ['affiliate_clawback', 'affiliate_receivable_settle', 'affiliate_receivable_reverse']))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('affiliate_receivable_settlements')->whereColumn('affiliate_receivable_settlements.user_id', 'users.id'))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('affiliate_receivable_reversals')->whereColumn('affiliate_receivable_reversals.user_id', 'users.id'));
        });
        $summary = $lockWait->execute(fn (): array => $batch->execute('receivables-audit', $users, 'id',
            fn (int $id) => AuditReceivableEvidenceJob::dispatch($id)));
        $state->heartbeat('receivables-audit', 'completed', $summary);

        return self::SUCCESS;
    }
}
