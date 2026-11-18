<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ExpandOperationsNotificationsAction
{
    public function execute(): void
    {
        NotificationEvent::where('scope', 'operations')->whereNull('expanded_at')->orderBy('id')->limit(100)->pluck('id')->each(function (int $id): void {
            // This phase is independent of the financial transaction that recorded the event.
            $ids = User::where(fn ($query) => $query->whereHas('permissions', fn ($permission) => $permission->where('name', 'finance.view'))
                ->orWhereHas('roles.permissions', fn ($permission) => $permission->where('name', 'finance.view')))
                ->whereNull('anonymized_at')->orderBy('id')->pluck('id');
            DB::transaction(function () use ($id, $ids): void {
                $users = User::whereKey($ids)->whereNull('anonymized_at')->orderBy('id')->lockForUpdate()->get();
                $event = NotificationEvent::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($event->expanded_at !== null) {
                    return;
                }
                $eligible = $users->filter(fn (User $user): bool => $user->can('finance.view'));
                if ($eligible->isEmpty()) {
                    $event->update(['suppression_reason' => 'awaiting_operations_recipient']);

                    return;
                }
                foreach ($eligible as $user) {
                    app(RecordNotificationEventAction::class)->expand($event, $user);
                }
                $event->update(['expanded_at' => now(), 'suppression_reason' => null]);
            });
        });
    }
}
