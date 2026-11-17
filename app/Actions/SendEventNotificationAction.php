<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendEventNotificationJob;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\NotificationManager;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendEventNotificationAction
{
    public function execute(SendEventNotificationJob $job): void
    {
        $status = 'failed';
        $error = null;
        $user = User::find($job->userId);
        $binding = $job->bindingId === null ? null : UserNotificationBinding::whereKey($job->bindingId)->where('user_id', $job->userId)->whereNotNull('verified_at')->first();
        $valid = $user !== null && ($job->bindingId === null
            ? $job->channel === 'email' && $user->email === $job->target && ! UserNotificationBinding::where('user_id', $job->userId)->whereIn('channel', ['email', 'mail'])->whereNotNull('verified_at')->exists()
            : $binding !== null && $binding->target === $job->target && ($binding->channel === 'mail' ? 'email' : $binding->channel) === $job->channel);
        $template = NotificationTemplate::where('event_key', $job->event)->where('channel', $job->channel)->first();
        if (! $valid || ! in_array($job->event, NotificationManager::EVENTS, true)) {
            $status = 'skipped';
            $error = 'Recipient or event no longer valid.';
        } elseif ($template !== null && ! $template->is_enabled) {
            $status = 'disabled';
            $error = 'Template explicitly disabled.';
        } else {
            $variables = array_intersect_key($job->variables, array_flip(NotificationManager::VARIABLES));
            $replacements = array_fill_keys(array_map(fn ($key) => '{{'.$key.'}}', NotificationManager::VARIABLES), '');
            foreach ($variables as $key => $value) {
                if (is_scalar($value)) {
                    $replacements['{{'.$key.'}}'] = mb_substr((string) $value, 0, 255);
                }
            }
            $defaults = NotificationManager::DEFAULTS[$job->event];
            $subject = strtr($template->subject ?? $defaults[0], $replacements);
            $body = strtr($template->body_markdown ?? $defaults[1].' 请登录客户中心查看。', $replacements);
            $params = [];
            foreach ($template->variable_mapping ?? [] as $external => $key) {
                if (in_array($key, NotificationManager::VARIABLES, true)) {
                    $params[$external] = $variables[$key] ?? '';
                }
            }
            try {
                $drivers = app(DriverManager::class);
                $ok = match ($job->channel) {
                    'email' => app(SettingService::class)->get('mail.transport', 'log') === 'log' || $drivers->mail()->send($job->target, $subject, $body),
                    'telegram' => $drivers->bot()->send($job->target, $body),
                    'sms' => $template?->external_template_id !== null && $drivers->sms()->sendTemplate($job->target, $template->external_template_id, $params),
                    default => false,
                };
                $simulation = ($job->channel === 'email' && app(SettingService::class)->get('mail.transport', 'log') === 'log') || ($job->channel === 'sms' && app(SettingService::class)->get('sms.driver', 'log') === 'log');
                $status = $ok ? ($simulation ? 'simulated' : 'sent') : 'failed';
                if (! $ok) {
                    $error = 'Transport unavailable or rejected; SMS requires an audited template.';
                } else {
                    $notes = [];
                    if ($job->bindingId === null) {
                        $notes[] = 'Account email fallback.';
                    }
                    if ($template === null) {
                        $notes[] = 'Built-in default template.';
                    }
                    $error = $notes === [] ? null : implode(' ', $notes);
                }
            } catch (\Exception $e) {
                // Each channel has its own job; report failure without secrets or blocking other channels.
                $error = 'Transport failed: '.get_class($e);
            }
        }
        DB::transaction(fn () => DB::table('notification_logs')->insert([
            'user_id' => $user?->id, 'event_key' => $job->event, 'channel' => $job->channel, 'target' => $job->target,
            'status' => $status, 'error_message' => $error, 'created_at' => now(), 'updated_at' => now(),
        ]));
        if ($status === 'simulated') {
            Log::info('Event notification simulated; no external delivery.', ['event' => $job->event, 'user_id' => $job->userId]);
        }
        if ($status === 'failed') {
            Log::warning('Event notification failed.', ['event' => $job->event, 'channel' => $job->channel, 'user_id' => $job->userId]);
        }
    }
}
