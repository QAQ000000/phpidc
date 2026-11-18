<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordNotificationEventAction
{
    public function execute(?int $userId, string $event, array $variables, ?string $eventKey, ?int $invoiceId = null, ?array $recipient = null, bool $suppressOperationsFanout = false): NotificationEvent
    {
        if (! in_array($event, NotificationManager::EVENTS, true)) {
            throw new DomainException('Unknown notification event.');
        }
        if ($suppressOperationsFanout && $userId !== null) {
            throw new DomainException('Only an operations fact may suppress recursive fanout.');
        }
        $safe = [];
        foreach (array_intersect_key($variables, array_flip(NotificationManager::VARIABLES)) as $key => $value) {
            if (is_scalar($value)) {
                $safe[$key] = mb_substr((string) $value, 0, 255);
            }
        }
        ksort($safe);
        $eventKey ??= 'legacy-call:'.Str::ulid();
        if (strlen($eventKey) > 191 || preg_match('/^[A-Za-z0-9:_.-]+$/D', $eventKey) !== 1) {
            throw new DomainException('A bounded stable notification event key is required.');
        }

        $ownsTransaction = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($userId, $event, $safe, $eventKey, $invoiceId, $recipient, $ownsTransaction, $suppressOperationsFanout): NotificationEvent {
            if ($ownsTransaction && $userId !== null) {
                User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
            }
            $dedupe = hash('sha256', ($userId === null ? 'operations' : 'user:'.$userId).'|'.$eventKey);
            $user = $userId === null ? null : User::withTrashed()->findOrFail($userId);
            $closed = $user !== null && ($user->trashed() || $user->anonymized_at !== null);
            // A unique insert also serializes operations events, which deliberately have no recipient user lock.
            $record = NotificationEvent::query()->createOrFirst(['dedupe_key' => $dedupe], ['user_id' => $userId, 'scope' => $userId === null ? 'operations' : 'user', 'event_type' => $event,
                'event_key' => $eventKey, 'encrypted_payload' => $closed ? null : ['version' => 1, 'variables' => $safe, 'invoice_id' => $invoiceId],
                'expanded_at' => $closed || $suppressOperationsFanout ? now() : null,
                'suppression_reason' => $closed ? 'account_closed' : ($suppressOperationsFanout ? 'operations_fanout_suppressed' : null)]);
            if (! $record->wasRecentlyCreated) {
                if ($record->event_type !== $event || ($record->encrypted_payload !== null && $record->encrypted_payload['variables'] !== $safe)) {
                    throw new DomainException('Notification event key was reused with different data.');
                }

                return $record;
            }
            if (! $closed && $user !== null) {
                $this->expand($record, $user, $invoiceId, $recipient);
            }

            return $record;
        });
    }

    /** Caller holds the user and event locks. Inline business recording also has a hard 100-item bound. */
    public function expand(NotificationEvent $event, User $user, ?int $invoiceId = null, ?array $recipient = null, int $maxRecipients = 100): int
    {
        if ($recipient !== null) {
            // Legacy jobs adopt exactly their original explicit target and do not enqueue another wakeup.
            $this->recordRecipient($event, $user, $recipient, $invoiceId, false);
            $event->update(['expanded_at' => now()]);

            return 1;
        }
        $maxRecipients = max(0, min(100, $maxRecipients));
        if ($maxRecipients === 0) {
            return 0;
        }
        $invoiceId ??= $event->encrypted_payload['invoice_id'] ?? null;
        if ($event->recipient_binding_upper_bound === null) {
            $event->update(['recipient_cursor' => $user->id, 'recipient_binding_cursor' => 0,
                'recipient_binding_upper_bound' => (int) (UserNotificationBinding::where('user_id', $user->id)->max('id') ?? 0),
                ...($event->scope === 'user' ? ['recipient_upper_bound' => $user->id] : [])]);
        }
        $cost = 0;
        $bindings = $this->remainingBindings($event, $user)->limit($maxRecipients)->get();
        foreach ($bindings as $binding) {
            $cost++;
            $event->recipient_binding_cursor = $binding->id;
            if ($binding->verified_at !== null) {
                $this->recordRecipient($event, $user, ['channel' => $binding->channel, 'target' => $binding->target, 'binding_id' => $binding->id, 'fallback' => false], $invoiceId);
            }
        }
        $event->save();
        if ($this->remainingBindings($event, $user)->exists()) {
            return $cost;
        }
        $hasEmail = UserNotificationBinding::where('user_id', $user->id)->where('id', '<=', $event->recipient_binding_upper_bound)
            ->whereIn('channel', ['email', 'mail'])->whereNotNull('verified_at')->exists();
        if (! $hasEmail) {
            if ($cost >= $maxRecipients) {
                return $cost;
            }
            $cost++;
            $this->recordRecipient($event, $user, ['channel' => 'email', 'target' => $user->email, 'binding_id' => null, 'fallback' => true], $invoiceId);
        }
        $event->update(['recipient_binding_cursor' => 0, 'recipient_binding_upper_bound' => null,
            ...($event->scope === 'user' ? ['expanded_at' => now(), 'suppression_reason' => null] : [])]);

        return $cost;
    }

    /** @return Builder<UserNotificationBinding> */
    private function remainingBindings(NotificationEvent $event, User $user): Builder
    {
        return UserNotificationBinding::where('user_id', $user->id)->where('id', '>', $event->recipient_binding_cursor)
            ->where('id', '<=', $event->recipient_binding_upper_bound)->orderBy('id');
    }

    private function recordRecipient(NotificationEvent $event, User $user, array $selected, ?int $invoiceId, bool $dispatch = true): void
    {
        $channel = $selected['channel'] === 'mail' ? 'email' : $selected['channel'];
        if (! in_array($channel, ['email', 'sms', 'telegram'], true)) {
            return;
        }
        $key = 'user:'.$user->id.':'.($selected['binding_id'] === null ? 'account-email' : 'binding:'.$selected['binding_id']);
        if (NotificationDelivery::where('notification_event_id', $event->id)->where('channel', $channel)->where('recipient_key', $key)->exists()) {
            return;
        }
        $variables = $event->encrypted_payload['variables'];
        $message = app(RenderNotificationMessageAction::class)->execute($event->event_type, $channel, $variables, $invoiceId, $selected['fallback']);
        $delivery = NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $user->id, 'channel' => $channel,
            'recipient_key' => $key, 'binding_id' => $selected['binding_id'], 'is_fallback' => $selected['fallback'], 'job_kind' => $invoiceId === null ? 'event' : 'renewal',
            'encrypted_payload' => ['version' => 1, 'target' => $selected['target'], 'variables' => $variables, 'invoice_id' => $invoiceId, ...$message],
            'status' => 'pending', 'available_at' => now()]);
        if ($dispatch) {
            DB::afterCommit(fn () => app(DispatchNotificationDeliveryAction::class)->execute($delivery->id));
        }
    }
}
