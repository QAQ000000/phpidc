<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationTemplate;
use App\Services\NotificationManager;

class RenderNotificationMessageAction
{
    public function execute(string $event, string $channel, array $variables, ?int $invoiceId = null, bool $fallback = false): array
    {
        $template = NotificationTemplate::where('event_key', $event)->where('channel', $channel)->first();
        if ($invoiceId !== null && $template === null) {
            return ['subject' => '服务续费提醒', 'body' => '续费账单 '.$variables['invoice_no'].'：'.$variables['amount'].' '.$variables['currency'].'，'.url('/console/invoices/'.$invoiceId),
                'sms_template' => 'renewal.invoice', 'params' => [], 'legacy_sms' => true,
                'log_event_key' => 'renewal.invoice.'.$invoiceId.($fallback ? '.fallback' : '')];
        }
        $replacements = array_fill_keys(array_map(fn ($key) => '{{'.$key.'}}', NotificationManager::VARIABLES), '');
        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }
        $defaults = NotificationManager::DEFAULTS[$event];
        $params = [];
        foreach ($template->variable_mapping ?? [] as $external => $key) {
            if (in_array($key, NotificationManager::VARIABLES, true)) {
                $params[$external] = $variables[$key] ?? '';
            }
        }

        return ['subject' => strtr($template->subject ?? $defaults[0], $replacements),
            'body' => strtr($template->body_markdown ?? $defaults[1].' 请登录客户中心查看。', $replacements),
            'sms_template' => $template?->external_template_id, 'params' => $params, 'legacy_sms' => false, 'default_template' => $template === null,
            'log_event_key' => $event];
    }
}
