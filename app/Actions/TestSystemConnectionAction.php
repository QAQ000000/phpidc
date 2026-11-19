<?php

declare(strict_types=1);

namespace App\Actions;

use App\Drivers\Notification\IdcsmartSmsDriver;
use App\Drivers\Notification\TelegramBotDriver;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use App\Services\SettingService;
use App\Support\AdminAuditContext;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class TestSystemConnectionAction
{
    /** @return array{status: string, bot_username?: string} */
    public function execute(User $actor, string $channel): array
    {
        abort_unless(in_array($channel, ['mail', 'sms', 'telegram'], true), 404);
        $target = DB::transaction(function () use ($actor, $channel): string {
            $operator = User::whereKey($actor->id)->whereNull('anonymized_at')->lockForUpdate()->first();
            abort_if($operator === null, 403);
            Gate::forUser($operator)->authorize('settings.view');
            Gate::forUser($operator)->authorize($channel === 'telegram' ? 'settings.identity.manage' : 'settings.notification.manage');
            if ($channel === 'mail') {
                if ($operator->email_verified_at === null) {
                    throw ValidationException::withMessages(['test' => '请先在账户安全页验证本人邮箱。']);
                }

                return $operator->email;
            }
            if ($channel === 'sms') {
                if (! is_string($operator->phone) || ! UserNotificationBinding::where('user_id', $operator->id)
                    ->where('channel', 'sms')->where('target', $operator->phone)->whereNotNull('verified_at')->exists()) {
                    throw ValidationException::withMessages(['test' => '请先在账户安全页验证本人手机号。']);
                }

                return $operator->phone;
            }

            return '';
        });
        // A probe is a single explicit external call after the authorization transaction.
        try {
            $result = $this->probe($channel, $target);
        } catch (RetryableNotificationException) {
            $result = ['status' => 'retryable'];
        } catch (UncertainNotificationException|TransportExceptionInterface) {
            $result = ['status' => 'uncertain'];
        }
        DB::table('audit_logs')->insert(['actor_type' => 'user', 'actor_id' => $actor->id,
            'action' => 'settings.connection_test', 'subject_type' => 'system_settings',
            'reason' => '管理员测试已保存的接口配置',
            'after' => json_encode(['channel' => $channel, 'status' => $result['status']], JSON_THROW_ON_ERROR),
            ...AdminAuditContext::fields(true), 'created_at' => now(), 'updated_at' => now()]);

        return $result;
    }

    /** @return array{status: string, bot_username?: string} */
    private function probe(string $channel, string $target): array
    {
        $settings = app(SettingService::class);
        if ($channel === 'telegram') {
            $username = app(TelegramBotDriver::class)->identity();

            return $username === null ? ['status' => 'rejected'] : ['status' => 'connected', 'bot_username' => $username];
        }
        if ($channel === 'mail') {
            $sent = app(DriverManager::class)->mail()->send($target, '系统邮件测试', '这是一封由管理员手动发送的系统配置测试邮件。');

            return ['status' => ! $sent ? 'rejected' : ($settings->get('mail.transport', 'log') === 'log' ? 'simulated' : 'sent')];
        }
        $sms = app(DriverManager::class)->sms();
        if ($sms instanceof IdcsmartSmsDriver && ! $sms->hasTemplate('system.settings_test')) {
            throw ValidationException::withMessages(['test' => '请先配置 system.settings_test 短信模板，仅支持 message 变量。']);
        }
        $sent = $sms->sendTemplate($target, 'system.settings_test', ['message' => '系统配置测试']);
        if ($sms instanceof IdcsmartSmsDriver && $sms->deliveryUncertain) {
            return ['status' => 'uncertain'];
        }

        return ['status' => ! $sent ? 'rejected' : ($settings->get('sms.driver', 'log') === 'log' ? 'simulated' : 'sent')];
    }
}
