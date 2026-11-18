<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ResolveNotificationDeliveryAction;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationOutboxController extends Controller
{
    public function resolve(Request $request, NotificationDelivery $delivery, ResolveNotificationDeliveryAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['decision' => ['required', 'in:accepted,retry'], 'evidence' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted'], 'worker_stopped' => ['sometimes', 'boolean'], 'not_accepted' => ['sometimes', 'boolean']]);
        $action->execute($actor, $delivery, $data['decision'], $data['evidence'], $data['reason'], $request->boolean('worker_stopped'), $request->boolean('not_accepted'));

        return redirect('/admin/notification-outbox')->with('inventory_status', '投递核对结果已保存，原事件和尝试记录保留。');
    }
}
