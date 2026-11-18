<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NotificationExpansionFailed;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class FairMaintenanceBatch
{
    public function __construct(private readonly MaintenanceState $state) {}

    /** Candidates remain authoritative in their business tables; a lost cursor safely starts a new round. */
    public function execute(string $kind, Builder $query, string $oldestColumn, Closure $consume, ?Closure $clock = null, ?MaintenanceBudget $budget = null, ?Builder $priorityQuery = null, ?int $priorityLimit = null, ?int $rotationLimit = null): array
    {
        $this->state->assertKind($kind);
        $budget ??= new MaintenanceBudget($clock);
        $limit = min(100, $budget->remaining(), max(1, (int) config('maintenance.limit', 100)));
        $half = min($limit, max(0, $priorityLimit ?? intdiv($limit, 2)));
        $rotationLimit = min($limit - $half, max(0, $rotationLimit ?? $limit - $half));
        $quota = $half + $rotationLimit;
        $summary = ['selected' => 0, 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'deferred' => 0, 'units' => 0];
        if (! $budget->available() || $quota === 0) {
            return [...$summary, 'budget_exhausted' => ! $budget->available()];
        }
        $priority = (clone ($priorityQuery ?? $query))->reorder()->orderBy($oldestColumn)->orderBy('id')->limit($half)->pluck('id')->all();
        $cursor = $this->state->cursor($kind);
        $rotationQuery = (clone $query)->reorder()->whereNotIn('id', $priority);
        $rotation = (clone $rotationQuery)->where('id', '>', $cursor)->orderBy('id')->limit($rotationLimit)->pluck('id')->all();
        if ($rotation === [] && $cursor > 0) {
            $this->state->advance($kind, 0);
            $rotation = (clone $rotationQuery)->orderBy('id')->limit($rotationLimit)->pluck('id')->all();
        }
        $summary['selected'] = count($priority) + count($rotation);
        // Interleave the two quotas so a slow oldest item cannot consume the entire rotation budget.
        for ($offset = 0; $offset < max(count($priority), count($rotation)); $offset++) {
            foreach (['rotation' => $rotation, 'priority' => $priority] as $source => $ids) {
                if (! isset($ids[$offset])) {
                    continue;
                }
                if (! $budget->available() || $summary['units'] >= $quota) {
                    return [...$summary, 'budget_exhausted' => true];
                }
                $remaining = min($budget->remaining(), $quota - $summary['units'], max(1, intdiv($quota, 2)));
                $id = (int) $ids[$offset];
                if ($source === 'rotation') {
                    $this->state->advance($kind, $id);
                }
                if (! $this->state->retryDue($kind, $id)) {
                    $summary['deferred']++;
                    $summary['units']++;
                    $budget->consume(1);

                    continue;
                }
                $summary['attempted']++;
                $cost = 1;
                try {
                    $used = $consume($id, $remaining);
                    $cost = is_int($used) ? max(1, $used) : 1;
                    if ($cost > $remaining) {
                        throw new \LogicException('A maintenance consumer exceeded its recipient quota.');
                    }
                    $this->state->succeeded($kind, $id);
                    $summary['succeeded']++;
                } catch (Throwable $error) {
                    if ($error instanceof NotificationExpansionFailed) {
                        $cost = max(1, min($remaining, $error->consumedUnits));
                    }
                    $this->state->failed($kind, $id, $error);
                    $summary['failed']++;
                }
                $summary['units'] += $cost;
                $budget->consume($cost);
            }
        }

        return [...$summary, 'budget_exhausted' => false];
    }
}
