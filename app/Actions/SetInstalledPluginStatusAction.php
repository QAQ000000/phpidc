<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Support\InstalledPlugins;
use Illuminate\Validation\ValidationException;

class SetInstalledPluginStatusAction
{
    public function execute(User $actor, string $category, string $key, bool $enabled, ?string $reason = null): void
    {
        $definition = InstalledPlugins::definitions($category)[$key] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(['plugin' => '不支持的内置插件。']);
        }
        if ($key === 'log' && ! $enabled) {
            throw ValidationException::withMessages(['plugin' => '日志模拟是回退通道，请启用其他插件以替换。']);
        }
        if ($category === 'payment') {
            app(ConfigureEpayAction::class)->setChannels($actor, $enabled, $reason);

            return;
        }
        $settings = match ($category) {
            'mail' => ['mail.transport' => $enabled ? $key : 'log'],
            'sms' => ['sms.driver' => $enabled ? $key : 'log'],
            'kyc' => ['kyc.enabled' => $enabled, 'kyc.driver' => $enabled ? $key : 'disabled'],
            'login' => ['oauth.'.$key.'_enabled' => $enabled],
            default => throw ValidationException::withMessages(['plugin' => '不支持的插件分类。']),
        };
        $reason = trim($reason ?? '');
        try {
            app(ConfigureIdentityAction::class)->execute($settings,
                $reason === '' ? ($enabled ? '启用' : '停用').$definition['name'].'插件' : $reason, $actor);
        } catch (ValidationException $exception) {
            if ($category === 'sms' && $settings['sms.driver'] === 'log'
                && ($exception->errors()['settings'] ?? []) === ['启用手机绑定前须配置真实短信通道及对应模板。']) {
                throw ValidationException::withMessages(['plugin' => '请先关闭手机绑定，再停用真实短信通道。']);
            }

            throw $exception;
        }
    }
}
