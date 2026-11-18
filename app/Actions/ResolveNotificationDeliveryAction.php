<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ResolveNotificationDeliveryAction
{
    public function execute(User $actor, NotificationDelivery $delivery, string $decision, string $evidence, string $reason, bool $workerStopped, bool $notAccepted): void
    {
        Gate::forUser($actor)->authorize('notification.manage');
        validator(compact('decision', 'evidence', 'reason'), ['decision' => ['required', 'in:accepted,retry'],
            'evidence' => ['required', 'string', 'max:1000'], 'reason' => ['required', 'string', 'max:255']])->validate();
        DB::transaction(function () use ($actor, $delivery, $decision, $evidence, $reason, $workerStopped, $notAccepted): void {
            $users = User::withTrashed()->whereKey([$actor->id, $delivery->user_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $operator = $users->get($actor->id);
            abort_unless($operator !== null && ! $operator->trashed(), 403);
            Gate::forUser($operator)->authorize('notification.manage');
            app(ExecuteNotificationDeliveryAction::class)->locked($delivery->id, function (NotificationDelivery $locked, User $user) use ($operator, $decision, $evidence, $reason, $workerStopped, $notAccepted): void {
                if ($locked->status !== 'uncertain' || $user->trashed() || $user->anonymized_at !== null || $locked->encrypted_payload === null
                    || ($decision === 'retry' && (! $workerStopped || ! $notAccepted || $locked->attempts >= $locked->max_attempts))) {
                    throw ValidationException::withMessages(['decision' => 'Only an unresolved current recipient with verified evidence and remaining budget may be retried.']);
                }
                if ($decision === 'accepted') {
                    $locked->update(['status' => 'sent', 'error_code' => 'manually_verified_accepted', 'finished_at' => now()]);
                } else {
                    $locked->update(['status' => 'pending', 'error_code' => 'manually_verified_not_accepted', 'send_started_at' => null,
                        'finished_at' => null, 'available_at' => now(), 'last_dispatched_at' => null]);
                    DB::afterCommit(fn () => app(DispatchNotificationDeliveryAction::class)->execute($locked->id));
                }
                OperationsAudit::record($operator, 'notification.resolve', NotificationDelivery::class, $locked->id, $reason,
                    ['status' => 'uncertain'], ['status' => $locked->status, 'evidence' => $evidence, 'worker_stopped' => $workerStopped, 'not_accepted' => $notAccepted]);
            });
        });
    }
}
