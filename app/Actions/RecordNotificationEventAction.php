<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordNotificationEventAction
{
    public function execute(?int $userId, string $event, array $variables, ?string $eventKey, ?int $invoiceId = null, ?array $recipient = null): NotificationEvent
    {
        if (! in_array($event, NotificationManager::EVENTS, true)) {
            throw new DomainException('Unknown notification event.');
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

        return DB::transaction(function () use ($userId, $event, $safe, $eventKey, $invoiceId, $recipient, $ownsTransaction): NotificationEvent {
            if ($ownsTransaction && $userId !== null) {
                User::withTrashed()->whereKey($userId)->lockForUpdate()->firstOrFail();
            }
            $dedupe = hash('sha256', ($userId === null ? 'operations' : 'user:'.$userId).'|'.$eventKey);
            $user = $userId === null ? null : User::withTrashed()->findOrFail($userId);
            $closed = $user !== null && ($user->trashed() || $user->anonymized_at !== null);
            // A unique insert also serializes operations events, which deliberately have no recipient user lock.
            $record = NotificationEvent::query()->createOrFirst(['dedupe_key' => $dedupe], ['user_id' => $userId, 'scope' => $userId === null ? 'operations' : 'user', 'event_type' => $event,
                'event_key' => $eventKey, 'encrypted_payload' => $closed ? null : ['version' => 1, 'variables' => $safe],
                'expanded_at' => $userId === null ? null : now(), 'suppression_reason' => $closed ? 'account_closed' : null]);
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

    public function expand(NotificationEvent $event, User $user, ?int $invoiceId = null, ?array $recipient = null): void
    {
        $recipients = $recipient === null ? $this->recipients($user) : [$recipient];
        foreach ($recipients as $selected) {
            $channel = $selected['channel'] === 'mail' ? 'email' : $selected['channel'];
            if (! in_array($channel, ['email', 'sms', 'telegram'], true)) {
                continue;
            }
            $key = 'user:'.$user->id.':'.($selected['binding_id'] === null ? 'account-email' : 'binding:'.$selected['binding_id']);
            if (NotificationDelivery::where('notification_event_id', $event->id)->where('channel', $channel)->where('recipient_key', $key)->exists()) {
                continue;
            }
            $variables = $event->encrypted_payload['variables'];
            $message = app(RenderNotificationMessageAction::class)->execute($event->event_type, $channel, $variables, $invoiceId, $selected['fallback']);
            $delivery = NotificationDelivery::create(['notification_event_id' => $event->id, 'user_id' => $user->id, 'channel' => $channel,
                'recipient_key' => $key, 'binding_id' => $selected['binding_id'], 'is_fallback' => $selected['fallback'], 'job_kind' => $invoiceId === null ? 'event' : 'renewal',
                'encrypted_payload' => ['version' => 1, 'target' => $selected['target'], 'variables' => $variables, 'invoice_id' => $invoiceId, ...$message],
                'status' => 'pending', 'available_at' => now()]);
            if ($recipient === null) {
                DB::afterCommit(fn () => app(DispatchNotificationDeliveryAction::class)->execute($delivery->id));
            }
        }
    }

    private function recipients(User $user): array
    {
        $bindings = UserNotificationBinding::where('user_id', $user->id)->whereNotNull('verified_at')->orderBy('id')->get();
        $recipients = [];
        foreach ($bindings as $binding) {
            $recipients[] = ['channel' => $binding->channel, 'target' => $binding->target, 'binding_id' => $binding->id, 'fallback' => false];
        }
        if (! $bindings->contains(fn ($binding) => in_array($binding->channel, ['email', 'mail'], true))) {
            $recipients[] = ['channel' => 'email', 'target' => $user->email, 'binding_id' => null, 'fallback' => true];
        }

        return $recipients;
    }
}
