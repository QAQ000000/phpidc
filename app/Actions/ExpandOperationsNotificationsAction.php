<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\NotificationExpansionFailed;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpandOperationsNotificationsAction
{
    public function execute(): void
    {
        $remaining = 100;
        $deadline = microtime(true) + 5;
        $ids = NotificationEvent::whereNull('expanded_at')->orderBy('id')->limit($remaining)->pluck('id');
        foreach ($ids as $id) {
            if ($remaining <= 0 || microtime(true) >= $deadline) {
                break;
            }
            $remaining -= max(1, $this->expandOne((int) $id, $remaining, fn (): bool => microtime(true) < $deadline));
        }
    }

    /** Returns scanned bindings/fallbacks, including revoked items; one call never scans over the quota. */
    public function expandOne(int $eventId, int $maxRecipients, ?Closure $withinBudget = null): int
    {
        if ($maxRecipients <= 0) {
            return 0;
        }
        $remaining = min(100, $maxRecipients);
        $cost = 0;
        $deadline = microtime(true) + 5;
        while ($remaining > 0 && microtime(true) < $deadline && ($withinBudget === null || $withinBudget())) {
            try {
                $used = $this->expandPage($eventId, $remaining);
                $event = NotificationEvent::find($eventId);
            } catch (Throwable $error) {
                // Earlier users may have committed. Charge the whole allowed scan even when this page rolls back.
                throw new NotificationExpansionFailed(min(100, $maxRecipients), $error);
            }
            $cost += $used;
            $remaining -= $used;
            if ($used === 0 || $event === null || $event->expanded_at !== null || $event->suppression_reason === 'awaiting_operations_recipient') {
                break;
            }
        }

        return $cost;
    }

    private function expandPage(int $eventId, int $maxRecipients): int
    {
        $snapshot = DB::transaction(function () use ($eventId): ?NotificationEvent {
            $event = NotificationEvent::whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->expanded_at !== null) {
                return null;
            }
            if ($event->scope === 'operations' && $event->recipient_upper_bound === null) {
                $event->update(['recipient_upper_bound' => (int) (User::max('id') ?? 0)]);
            }

            return $event;
        });
        if ($snapshot === null) {
            return 0;
        }
        $userId = $snapshot->scope === 'user' ? $snapshot->user_id : ($snapshot->recipient_binding_upper_bound !== null
            ? $snapshot->recipient_cursor : $this->nextOperator($snapshot)->value('id'));
        if ($userId === null) {
            return DB::transaction(function () use ($snapshot): int {
                $event = NotificationEvent::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                if ($this->samePosition($event, $snapshot)) {
                    $this->finishOperations($event);
                }

                return 1;
            });
        }

        // Never take a user lock after the event lock. A concurrent page is fenced by the durable position below.
        return DB::transaction(function () use ($snapshot, $userId, $maxRecipients): int {
            $user = User::withTrashed()->whereKey($userId)->lockForUpdate()->first();
            $event = NotificationEvent::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if (! $this->samePosition($event, $snapshot)) {
                return 1;
            }
            if ($user === null || $user->trashed() || $user->anonymized_at !== null || $event->encrypted_payload === null
                || ($event->scope === 'operations' && ! $user->can('finance.view'))) {
                if ($event->scope === 'user') {
                    $event->update(['expanded_at' => now(), 'suppression_reason' => 'account_closed', 'encrypted_payload' => null]);
                } else {
                    $event->update(['recipient_cursor' => $userId, 'recipient_binding_cursor' => 0, 'recipient_binding_upper_bound' => null]);
                    if (! $this->nextOperator($event)->exists()) {
                        $this->finishOperations($event);
                    }
                }

                return 1;
            }
            $cost = app(RecordNotificationEventAction::class)->expand($event, $user, maxRecipients: $maxRecipients);
            if ($event->scope === 'operations' && $event->recipient_binding_upper_bound === null && ! $this->nextOperator($event)->exists()) {
                $this->finishOperations($event);
            }

            return max(1, $cost);
        });
    }

    private function samePosition(NotificationEvent $event, NotificationEvent $snapshot): bool
    {
        return $event->expanded_at === null && $event->recipient_cursor === $snapshot->recipient_cursor
            && $event->recipient_upper_bound === $snapshot->recipient_upper_bound
            && $event->recipient_binding_cursor === $snapshot->recipient_binding_cursor
            && $event->recipient_binding_upper_bound === $snapshot->recipient_binding_upper_bound;
    }

    /** @return Builder<User> */
    private function nextOperator(NotificationEvent $event): Builder
    {
        return User::where('id', '>', $event->recipient_cursor)->where('id', '<=', $event->recipient_upper_bound)
            ->whereNull('anonymized_at')->where(fn ($query) => $query->whereHas('permissions', fn ($permission) => $permission->where('name', 'finance.view'))
            ->orWhereHas('roles.permissions', fn ($permission) => $permission->where('name', 'finance.view')))->orderBy('id');
    }

    private function finishOperations(NotificationEvent $event): void
    {
        if (NotificationDelivery::where('notification_event_id', $event->id)->exists()) {
            $event->update(['expanded_at' => now(), 'suppression_reason' => null]);

            return;
        }
        // No recipient is a visible retryable condition. A future operator must be discoverable even above the old bound.
        $event->update(['suppression_reason' => 'awaiting_operations_recipient', 'recipient_cursor' => 0,
            'recipient_upper_bound' => null, 'recipient_binding_cursor' => 0, 'recipient_binding_upper_bound' => null]);
    }
}
