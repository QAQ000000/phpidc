<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OperationalEvent;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

class RecordOperationalIncidentAction
{
    public function execute(string $event, array $variables, string $key, bool $suppressNotification = false): OperationalEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('An operational fact must share its source transaction.');
        }
        if (! in_array($event, ['fulfillment.failed', 'fulfillment.recovery-exhausted', 'upstream.rollback-failed', 'notification.uncertain'], true)
            || ($suppressNotification && $event !== 'notification.uncertain') || strlen($key) > 191
            || preg_match('/^[A-Za-z0-9:_.-]+$/D', $key) !== 1) {
            throw new DomainException('Unknown observed operational event or unstable event identity.');
        }
        $safe = [];
        foreach (array_intersect_key($variables, array_flip(['instance_id', 'task_id', 'operation_id', 'delivery_id', 'status'])) as $name => $value) {
            if (is_scalar($value)) {
                $safe[$name] = mb_substr((string) $value, 0, 255);
            }
        }
        $record = OperationalEvent::query()->createOrFirst(['event_key' => $key], ['event_type' => $event, 'variables' => $safe,
            'notification_suppressed' => $suppressNotification, 'observed_at' => now()]);
        if ($record->event_type !== $event || Arr::sortRecursive($record->variables) !== Arr::sortRecursive($safe)
            || $record->notification_suppressed !== $suppressNotification) {
            throw new DomainException('An observed event key cannot be reused with different facts.');
        }

        return $record;
    }
}
