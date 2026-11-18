<?php

declare(strict_types=1);

namespace App\Actions;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

/** Only observed execution failures survive rollback; proposed financial changes must not. */
class RecordObservedOperationalEventAction
{
    /** @param Closure(Closure): mixed $work */
    public function transaction(Closure $work): mixed
    {
        $observed = [];
        $record = function (string $event, array $variables, string $key, bool $suppressOperationsFanout = false) use (&$observed): void {
            if (! in_array($event, ['fulfillment.failed', 'fulfillment.recovery-exhausted', 'upstream.rollback-failed', 'notification.uncertain'], true)) {
                throw new DomainException('Only an observed execution failure can survive its local transaction rollback.');
            }
            $observed[$key] = [$event, $variables, $key, $suppressOperationsFanout];
            app(RecordOperationalIncidentAction::class)->execute($event, $variables, $key, $suppressOperationsFanout);
        };
        try {
            return DB::transaction(fn () => $work($record));
        } catch (Throwable $original) {
            // The owning action transaction has now rolled back. An alert failure never replaces its error.
            foreach ($observed as [$event, $variables, $key, $suppressOperationsFanout]) {
                try {
                    DB::transaction(fn () => app(RecordOperationalIncidentAction::class)->execute($event, $variables, $key, $suppressOperationsFanout));
                } catch (Throwable $error) {
                    $context = ['event_type' => $event, 'event_key' => $key, 'failure_class' => get_class($error),
                        'source_ids' => array_intersect_key($variables, array_flip(['instance_id', 'task_id', 'operation_id', 'delivery_id']))];
                    // A separate production logger still reaches PHP's configured error sink if Laravel logging fails.
                    $journal = new Logger('operational-events', [new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Level::Critical)]);
                    $journal->critical('operational_event_persistence_failed', $context);
                    try {
                        Log::critical('Observed operational event could not be persisted after rollback.', $context);
                    } catch (Throwable $loggingError) {
                        $journal->critical('operational_event_log_failed', ['failure_class' => get_class($loggingError)]);
                    }
                }
            }
            throw $original;
        }
    }
}
