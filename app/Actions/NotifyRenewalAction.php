<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendNotificationJob;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class NotifyRenewalAction
{
    public function execute(Invoice $invoice): void
    {
        $bindings = DB::table('user_notification_bindings')->where('user_id', $invoice->user_id)->whereNotNull('verified_at')->get();
        $email = false;
        foreach ($bindings as $binding) {
            if (in_array($binding->channel, ['mail', 'email'], true)) {
                $email = true;
            }
            SendNotificationJob::dispatch($invoice->id, $binding->channel, $binding->target);
        }
        if (! $email) {
            SendNotificationJob::dispatch($invoice->id, 'email', $invoice->user->email, true);
        }
    }
}
