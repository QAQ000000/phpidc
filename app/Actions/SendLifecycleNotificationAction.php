<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\Notification\IdcsmartSmsDriver;
use App\Jobs\SendEventNotificationJob;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendLifecycleNotificationAction
{
    public function execute(int $id, string $channel, string $target, bool $fallback = false, int $attempt = 1): void
    {
        $invoice = Invoice::findOrFail($id);
        $canonicalChannel = $channel === 'mail' ? 'email' : $channel;
        if (NotificationTemplate::where('event_key', 'renewal.invoice')->where('channel', $canonicalChannel)->exists()) {
            $binding = UserNotificationBinding::where('user_id', $invoice->user_id)->whereIn('channel', $canonicalChannel === 'email' ? ['email', 'mail'] : [$canonicalChannel])->where('target', $target)->whereNotNull('verified_at')->first();
            app(SendEventNotificationAction::class)->execute(new SendEventNotificationJob($invoice->user_id, 'renewal.invoice', $canonicalChannel, $target,
                ['invoice_no' => $invoice->invoice_no, 'amount' => $invoice->total_amount, 'currency' => $invoice->currency], $binding?->id), $attempt);

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
        $retry = null;
        try {
            $sms = $channel === 'sms' ? app(DriverManager::class)->sms() : null;
            $ok = match ($channel) {
                'email', 'mail' => app(DriverManager::class)->mail()->send($target, '服务续费提醒', $body),
                'telegram' => app(DriverManager::class)->bot()->send($target, $body),
                'sms' => $sms->sendTemplate($target, 'renewal.invoice', ['body' => $body]),
                default => false,
            };
            if (! $ok) {
                $error = $sms instanceof IdcsmartSmsDriver && $sms->deliveryUncertain
                    ? 'Delivery uncertain; manual verification required. No automatic replay.'
                    : 'Permanent rejection or unconfigured notification transport.';
            }
        } catch (RetryableNotificationException $e) {
            $retry = $e;
            $error = 'Retryable rejection; attempt '.$attempt.'/3.';
        } catch (UncertainNotificationException) {
            $error = 'Delivery uncertain; manual verification required. No automatic replay.';
        } catch (\Exception $e) {
            // Channel failure is recorded independently. Do not leak SMTP passwords or bot tokens from exception text.
            $error = 'Notification transport failed: '.get_class($e);
        }
        $simulation = ($canonicalChannel === 'email' && app(SettingService::class)->get('mail.transport', 'log') === 'log')
            || ($canonicalChannel === 'sms' && app(SettingService::class)->get('sms.driver', 'log') === 'log');
        DB::table('notification_logs')->insert(['user_id' => $invoice->user_id, 'event_key' => 'renewal.invoice.'.$id.($fallback ? '.fallback' : ''),
            'channel' => $canonicalChannel, 'target' => $target, 'status' => $ok ? ($simulation ? 'simulated' : 'sent') : 'failed', 'error_message' => $error,
            'created_at' => now(), 'updated_at' => now()]);
        if ($retry !== null) {
            throw $retry;
        }
        if ($fallback) {
            Log::info('Renewal mail falls back to account email.', ['invoice_id' => $id, 'user_id' => $invoice->user_id]);
        }
        if (! $ok) {
            Log::warning('Renewal notification failed.', ['invoice_id' => $id, 'channel' => $channel]);
        }
    }
}
