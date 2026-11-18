<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\Notification\IdcsmartSmsDriver;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExecuteNotificationDeliveryAction
{
    public function execute(int $id): void
    {
        $worker = (string) Str::uuid();
        $claimed = $this->locked($id, function (NotificationDelivery $delivery, User $user) use ($worker): bool {
            if (! in_array($delivery->status, ['pending', 'retry_wait'], true) || $delivery->available_at?->isFuture()) {
                return false;
            }
            if (($invalid = $this->invalid($delivery, $user)) !== null) {
                $this->finish($delivery, $user, $invalid, $invalid);

                return false;
            }
            if ($delivery->attempts >= $delivery->max_attempts) {
                $this->finish($delivery, $user, 'failed', 'attempts_exhausted');

                return false;
            }
            $delivery->update(['status' => 'running', 'attempts' => $delivery->attempts + 1, 'locked_by' => $worker,
                'locked_at' => now(), 'lease_expires_at' => now()->addSeconds(120), 'send_started_at' => null, 'error_code' => null]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        $payload = $this->locked($id, function (NotificationDelivery $delivery, User $user) use ($worker): ?array {
            if (! $this->current($delivery, $worker)) {
                return null;
            }
            if (($invalid = $this->invalid($delivery, $user)) !== null) {
                $this->finish($delivery, $user, $invalid, $invalid);

                return null;
            }
            $delivery->update(['send_started_at' => now()]);

            return $delivery->encrypted_payload + ['channel' => $delivery->channel];
        });
        if ($payload === null) {
            return;
        }
        $retry = null;
        $status = 'failed';
        $code = 'permanent_rejection';
        $note = 'Permanent rejection or unconfigured notification transport.';
        try {
            $drivers = app(DriverManager::class);
            $sms = $payload['channel'] === 'sms' ? $drivers->sms() : null;
            $params = $payload['legacy_sms'] ? ['body' => $payload['body']] : $payload['params'];
            $ok = match ($payload['channel']) {
                'email' => $drivers->mail()->send($payload['target'], $payload['subject'], $payload['body']),
                'telegram' => $drivers->bot()->send($payload['target'], $payload['body']),
                'sms' => $payload['sms_template'] !== null && $sms->sendTemplate($payload['target'], $payload['sms_template'], $params),
                default => false,
            };
            if ($ok) {
                $simulation = ($payload['channel'] === 'email' && app(SettingService::class)->get('mail.transport', 'log') === 'log')
                    || ($payload['channel'] === 'sms' && app(SettingService::class)->get('sms.driver', 'log') === 'log');
                $status = $simulation ? 'simulated' : 'sent';
                $code = $simulation ? 'simulated' : 'accepted';
                $note = null;
            } elseif ($sms instanceof IdcsmartSmsDriver && $sms->deliveryUncertain) {
                $status = 'uncertain';
            }
        } catch (RetryableNotificationException $error) {
            $retry = $error;
            $status = 'retry_wait';
            $code = 'temporary_rejection';
        } catch (UncertainNotificationException) {
            $status = 'uncertain';
        } catch (\Exception $error) {
            $status = 'uncertain';
            $code = 'transport_exception';
            $note = 'Transport failed: '.get_class($error);
        }
        if ($status === 'uncertain') {
            if ($code !== 'transport_exception') {
                $code = 'delivery_uncertain';
                $note = 'Delivery uncertain; manual verification required. No automatic replay.';
            }
        }
        $this->locked($id, function (NotificationDelivery $delivery, User $user) use ($worker, $status, $code, $note, $retry): void {
            if (! $this->current($delivery, $worker)) {
                return;
            }
            $state = $status;
            $message = $note;
            if ($retry !== null) {
                $state = $delivery->attempts >= $delivery->max_attempts ? 'failed' : 'retry_wait';
                $message = 'Retryable rejection; attempt '.$delivery->attempts.'/'.$delivery->max_attempts.'.';
                $delivery->available_at = now()->addSeconds(max($retry->retryAfter, [60, 300, 900][min($delivery->attempts - 1, 2)]));
            }
            $this->finish($delivery, $user, $state, $code, $message);
        });
        if ($retry !== null) {
            throw $retry;
        }
    }

    public function locked(int $id, callable $callback): mixed
    {
        $hint = NotificationDelivery::findOrFail($id);

        return DB::transaction(function () use ($hint, $callback): mixed {
            $user = User::withTrashed()->whereKey($hint->user_id)->lockForUpdate()->firstOrFail();
            NotificationEvent::whereKey($hint->notification_event_id)->lockForUpdate()->firstOrFail();
            $delivery = NotificationDelivery::whereKey($hint->id)->lockForUpdate()->firstOrFail();

            return $callback($delivery, $user);
        });
    }

    public function finish(NotificationDelivery $delivery, User $user, string $status, string $code, ?string $message = null): void
    {
        $payload = $delivery->encrypted_payload;
        $closed = $user->trashed() || $user->anonymized_at !== null;
        $event = NotificationEvent::findOrFail($delivery->notification_event_id);
        DB::table('notification_logs')->insertOrIgnore(['notification_delivery_id' => $delivery->id, 'attempt_no' => $delivery->attempts,
            'user_id' => $user->id, 'event_key' => $payload['log_event_key'] ?? $event->event_type, 'channel' => $delivery->channel,
            'target' => $closed ? '[anonymized]' : ($payload['target'] ?? '[redacted]'),
            'status' => in_array($status, ['uncertain', 'retry_wait'], true) ? 'failed' : $status, 'error_message' => $message,
            'created_at' => now(), 'updated_at' => now()]);
        $delivery->fill(['status' => $status, 'error_code' => $code, 'locked_by' => null, 'locked_at' => null, 'lease_expires_at' => null,
            'finished_at' => $status === 'retry_wait' ? null : now()]);
        if ($status === 'retry_wait') {
            $delivery->send_started_at = null;
        }
        if ($closed) {
            $delivery->encrypted_payload = null;
        }
        $delivery->save();
    }

    private function current(NotificationDelivery $delivery, string $worker): bool
    {
        return $delivery->status === 'running' && $delivery->locked_by === $worker && $delivery->lease_expires_at?->isFuture() === true;
    }

    private function invalid(NotificationDelivery $delivery, User $user): ?string
    {
        if ($user->trashed() || $user->anonymized_at !== null || $delivery->encrypted_payload === null) {
            return 'skipped';
        }
        $target = $delivery->encrypted_payload['target'];
        $valid = $delivery->binding_id === null
            ? $delivery->is_fallback && $delivery->channel === 'email' && $user->email === $target
                && ! UserNotificationBinding::where('user_id', $user->id)->whereIn('channel', ['mail', 'email'])->whereNotNull('verified_at')->exists()
            : UserNotificationBinding::whereKey($delivery->binding_id)->where('user_id', $user->id)->where('target', $target)
                ->whereIn('channel', $delivery->channel === 'email' ? ['mail', 'email'] : [$delivery->channel])->whereNotNull('verified_at')->exists();
        if (! $valid) {
            return 'skipped';
        }
        $event = NotificationEvent::findOrFail($delivery->notification_event_id);
        if ($event->scope === 'operations' && ! $user->can('finance.view')) {
            return 'skipped';
        }

        return NotificationTemplate::where('event_key', $event->event_type)->where('channel', $delivery->channel)->where('is_enabled', false)->exists() ? 'disabled' : null;
    }
}
