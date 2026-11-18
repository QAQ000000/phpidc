<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\SmsDriverInterface;
use App\Services\SettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IdcsmartSmsDriver implements SmsDriverInterface
{
    /** Per-call transport evidence; never persisted by the driver. */
    public bool $deliveryUncertain = false;

    public function __construct(private readonly SettingService $settings) {}

    public function ready(): bool
    {
        return $this->endpoint() !== null && trim($this->settings->get('sms.idcsmart_api', '') ?? '') !== ''
            && trim($this->settings->get('sms.idcsmart_key', '') ?? '') !== '' && $this->signature() !== '';
    }

    public function hasTemplate(string $key): bool
    {
        return $this->template($key) !== null;
    }

    public function sendTemplate(string $phone, string $templateCode, array $params): bool
    {
        $this->deliveryUncertain = false;
        $template = $this->template($templateCode);
        // The supplied plugin's sendCnSms path removes +86; no global SMS protocol was supplied.
        if (! $this->ready() || $template === null || preg_match('/^\+861[0-9]{10}$/D', $phone) !== 1) {
            return false;
        }
        $vars = [];
        $content = preg_replace_callback('/@var\(([a-zA-Z0-9_]+)\)/', function (array $match) use ($params, &$vars): string {
            $value = $params[$match[1]] ?? null;
            if (! is_string($value) && ! is_int($value)) {
                return $match[0];
            }
            $vars[$match[1]] = (string) $value;

            return (string) $value;
        }, $template['content']);
        if ($content === null || str_contains($content, '@var(')) {
            return false;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()->asForm()->withHeaders([
                'api' => $this->settings->get('sms.idcsmart_api'), 'key' => $this->settings->get('sms.idcsmart_key'),
            ])->post($this->endpoint().'?action=send', ['to' => substr($phone, 3), 'content' => $this->signature().$content,
                'template_id' => $template['id'], 'vars' => $vars]);
        } catch (ConnectionException) {
            $this->deliveryUncertain = true;

            // Delivery may have occurred; do not replay a billable SMS or expose the request body.
            return false;
        }

        $body = $response->body();
        $result = json_decode(str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body, true);

        $this->deliveryUncertain = $response->serverError() || $response->redirect() || ! is_array($result) || ! array_key_exists('status', $result);

        return $response->status() === 200 && is_array($result) && in_array($result['status'] ?? null, [200, '200'], true);
    }

    private function endpoint(): ?string
    {
        $url = $this->settings->get('sms.idcsmart_endpoint', '') ?? '';
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['query']) && ! isset($parts['fragment']) ? $url : null;
    }

    private function signature(): string
    {
        $sign = trim(str_replace(['【', '】'], '', $this->settings->get('sms.idcsmart_sign', '') ?? ''));

        return $sign === '' ? '' : '【'.$sign.'】';
    }

    private function template(string $key): ?array
    {
        $templates = json_decode($this->settings->get('sms.idcsmart_templates', '{}') ?? '{}', true);
        $template = is_array($templates) ? ($templates[$key] ?? null) : null;

        return is_array($template) && is_string($template['id'] ?? null) && trim($template['id']) !== ''
            && is_string($template['content'] ?? null) && trim($template['content']) !== '' ? $template : null;
    }
}
