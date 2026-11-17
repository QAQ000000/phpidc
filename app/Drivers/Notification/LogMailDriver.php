<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\MailDriverInterface;
use App\Services\SettingService;
use Illuminate\Support\Facades\Mail;

class LogMailDriver implements MailDriverInterface
{
    public function __construct(private readonly SettingService $settings) {}

    public function send(string $to, string $subject, string $bodyMarkdown, array $variables = []): bool
    {
        $transport = $this->settings->get('mail.transport', 'log');
        $config = match ($transport) {
            'smtp' => ['transport' => 'smtp', 'host' => $this->settings->get('mail.host', ''),
                'port' => $this->settings->getInt('mail.port', 587), 'username' => $this->settings->get('mail.username', ''),
                'password' => $this->settings->get('mail.password', ''), 'timeout' => 30],
            'log' => ['transport' => 'log'],
            default => null,
        };
        if ($config === null) {
            return false;
        }
        Mail::build($config)->raw($bodyMarkdown, fn ($message) => $message->to($to)->from($this->settings->get('mail.from_address', 'noreply@example.test'))->subject($subject));

        return true;
    }
}
