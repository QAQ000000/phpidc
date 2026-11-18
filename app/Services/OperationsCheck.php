<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\MaintenanceState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class OperationsCheck
{
    public function __construct(private readonly MaintenanceState $maintenance) {}

    /** Read-only observations; this path never dispatches, repairs, sends or acknowledges an alert. */
    public function execute(): array
    {
        $checks = [];
        $database = false;
        try {
            DB::select('SELECT 1');
            $database = true;
            $checks[] = ['key' => 'database', 'status' => 'ok'];
        } catch (Throwable) {
            $checks[] = ['key' => 'database', 'status' => 'critical', 'code' => 'database_unavailable'];
        }
        try {
            Redis::connection(config('cache.stores.'.config('maintenance.cache_store').'.connection', 'default'))->ping();
            $checks[] = ['key' => 'redis', 'status' => 'ok'];
            foreach (config('maintenance.kinds') as $kind) {
                $state = $this->maintenance->snapshot($kind);
                $completed = $state['completed_at'] ?? null;
                $age = $completed === null ? null : max(0, now()->timestamp - $completed);
                $warning = $kind === 'catalog' ? 450 : 150;
                $critical = $kind === 'catalog' ? 480 : 180;
                $status = $age === null || $age > $critical ? 'critical' : ($age > $warning ? 'warning' : 'ok');
                if (($state['failed_at'] ?? 0) > ($completed ?? 0)) {
                    $status = 'critical';
                }
                $failures = array_slice($this->maintenance->failures($kind), 0, 20);
                if ($status === 'ok' && $failures !== []) {
                    $status = 'warning';
                }
                $checks[] = ['key' => 'maintenance:'.$kind, 'status' => $status, 'last_completed_at' => $completed,
                    'age_seconds' => $age, 'failure_samples' => $failures];
            }
        } catch (Throwable) {
            $checks[] = ['key' => 'redis', 'status' => 'critical', 'code' => 'redis_or_heartbeat_unavailable'];
        }
        if ($database) {
            try {
                $due = DB::table('notification_deliveries')->whereIn('status', ['pending', 'retry_wait'])->where('available_at', '<=', now());
                $checks[] = $this->measure('outbox_due', $due, 'available_at', 120, 300);
                $checks[] = $this->measure('notification_uncertain', DB::table('notification_deliveries')->where('status', 'uncertain'), 'updated_at', 0, 900);
                $checks[] = $this->measure('operational_events_pending', DB::table('operational_events')->whereNull('notified_at'), 'observed_at', 120, 300);
                $checks[] = $this->measure('observed_fulfillment_failures', DB::table('operational_events')
                    ->whereIn('event_type', ['fulfillment.failed', 'fulfillment.recovery-exhausted']));
                $checks[] = $this->measure('observed_rollback_failures', DB::table('operational_events')->where('event_type', 'upstream.rollback-failed'));
                $checks[] = $this->measure('operations_without_recipient', DB::table('notification_events')->where('scope', 'operations')
                    ->whereNull('expanded_at')->where('suppression_reason', 'awaiting_operations_recipient'));
                $checks[] = $this->measure('fulfillment_failed', DB::table('fulfillment_tasks')->where('status', 'failed'));
                $checks[] = $this->measure('upstream_rollback_failed', DB::table('upstream_operations')->where('path', '/apply_credit')
                    ->where('request_payload->use_credit', 0)->where(fn (Builder $query) => $query->whereNull('outcome')->orWhere('outcome', '!=', 'rolled_back')));
                $recipients = User::whereNull('anonymized_at')->where(fn ($query) => $query
                    ->whereHas('permissions', fn ($permission) => $permission->where('name', 'finance.view'))
                    ->orWhereHas('roles.permissions', fn ($permission) => $permission->where('name', 'finance.view')))->count();
                $checks[] = ['key' => 'configured_operations_recipients', 'status' => $recipients > 0 ? 'ok' : 'critical', 'count' => $recipients];
            } catch (Throwable) {
                $checks[] = ['key' => 'business_observations', 'status' => 'critical', 'code' => 'database_query_failed'];
            }
        }
        $statuses = array_column($checks, 'status');
        $status = in_array('critical', $statuses, true) ? 'critical' : (in_array('warning', $statuses, true) ? 'warning' : 'ok');

        return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'checks' => $checks];
    }

    private function measure(string $key, Builder $query, ?string $ageColumn = null, int $warning = 0, int $critical = 0): array
    {
        $count = (clone $query)->count();
        $samples = (clone $query)->orderBy('id')->limit(20)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $age = null;
        $status = $count === 0 ? 'ok' : 'critical';
        if ($ageColumn !== null && $count > 0) {
            $oldest = (clone $query)->min($ageColumn);
            $age = $oldest === null ? null : max(0, now()->timestamp - CarbonImmutable::parse($oldest)->timestamp);
            $status = $age === null || $age > $critical ? 'critical' : ($age >= $warning ? 'warning' : 'ok');
        }

        return ['key' => $key, 'status' => $status, 'count' => $count, 'oldest_age_seconds' => $age, 'sample_ids' => $samples];
    }
}
