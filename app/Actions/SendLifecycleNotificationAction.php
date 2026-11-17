<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendEventNotificationJob;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendLifecycleNotificationAction
{
    public function execute(int $id, string $channel, string $target, bool $fallback = false): void
    {
        $invoice = Invoice::findOrFail($id);
        $canonicalChannel = $channel === 'mail' ? 'email' : $channel;
        if (NotificationTemplate::where('event_key', 'renewal.invoice')->where('channel', $canonicalChannel)->exists()) {
            $binding = UserNotificationBinding::where('user_id', $invoice->user_id)->whereIn('channel', $canonicalChannel === 'email' ? ['email', 'mail'] : [$canonicalChannel])->where('target', $target)->whereNotNull('verified_at')->first();
            app(SendEventNotificationAction::class)->execute(new SendEventNotificationJob($invoice->user_id, 'renewal.invoice', $canonicalChannel, $target,
                ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency], $binding?->id));

            return;
        }
        $recipientStillValid = $fallback
            ? $invoice->user?->email === $target && ! DB::table('user_notification_bindings')->where('user_id', $invoice->user_id)->whereIn('channel', ['email', 'mail'])->whereNotNull('verified_at')->exists()
            : DB::table('user_notification_bindings')->where('user_id', $invoice->user_id)->whereIn('channel', $canonicalChannel === 'email' ? ['email', 'mail'] : [$canonicalChannel])->where('target', $target)->whereNotNull('verified_at')->exists();
        if (! $recipientStillValid) {
            DB::transaction(fn () => DB::table('notification_logs')->insert(['user_id' => $invoice->user_id,
                'event_key' => 'renewal.invoice.'.$id, 'channel' => $canonicalChannel, 'target' => $target, 'status' => 'skipped',
                'error_message' => 'Recipient no longer valid.', 'created_at' => now(), 'updated_at' => now()]));

            return;
        }
        $body = '续费账单 '.$invoice->invoice_no.'：'.$invoice->total_amount.' '.$invoice->currency.'，'.url('/console/invoices/'.$id);
        $ok = false;
        $error = null;
        try {
            $ok = match ($channel) {
                'email', 'mail' => app(DriverManager::class)->mail()->send($target, '服务续费提醒', $body),
                'telegram' => app(DriverManager::class)->bot()->send($target, $body),
                'sms' => app(DriverManager::class)->sms()->sendTemplate($target, 'renewal.invoice', ['body' => $body]),
                default => false,
            };
            if (! $ok) {
                $error = 'Notification transport rejected or is not configured.';
            }
        } catch (\Exception $e) {
            // Channel failure is recorded independently. Do not leak SMTP passwords or bot tokens from exception text.
            $error = 'Notification transport failed: '.get_class($e);
        }
        DB::table('notification_logs')->insert(['user_id' => $invoice->user_id, 'event_key' => 'renewal.invoice.'.$id.($fallback ? '.fallback' : ''),
            'channel' => $channel, 'target' => $target, 'status' => $ok ? 'sent' : 'failed', 'error_message' => $error,
            'created_at' => now(), 'updated_at' => now()]);
        if ($fallback) {
            Log::info('Renewal mail falls back to account email.', ['invoice_id' => $id, 'user_id' => $invoice->user_id]);
        }
        if (! $ok) {
            Log::warning('Renewal notification failed.', ['invoice_id' => $id, 'channel' => $channel]);
        }
    }
}
