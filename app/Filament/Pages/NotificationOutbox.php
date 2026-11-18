<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class NotificationOutbox extends Page
{
    protected static ?string $slug = 'notification-outbox';

    protected static ?string $title = '通知事件与投递核对';

    protected string $view = 'filament.pages.notification-outbox';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('notification.view') ?? false;
    }

    protected function getViewData(): array
    {
        return ['events' => NotificationEvent::query()->when(request()->integer('user_id'), fn ($query, $id) => $query->where('user_id', $id))
            ->latest('id')->paginate(20, ['id', 'user_id', 'scope', 'event_type', 'event_key', 'expanded_at', 'suppression_reason', 'created_at'], 'events_page'),
            'deliveries' => NotificationDelivery::query()->when(request()->integer('user_id'), fn ($query, $id) => $query->where('user_id', $id))
                ->when(in_array(request('delivery_status'), ['pending', 'running', 'retry_wait', 'uncertain', 'failed', 'sent', 'simulated', 'skipped', 'disabled'], true), fn ($query) => $query->where('status', request('delivery_status')))
                ->latest('id')->paginate(30, ['id', 'notification_event_id', 'user_id', 'channel', 'status', 'attempts', 'max_attempts', 'available_at', 'send_started_at', 'error_code'], 'deliveries_page')];
    }
}
