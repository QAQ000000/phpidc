<?php

declare(strict_types=1);

namespace App\Drivers\Notification;

use App\Contracts\MailDriverInterface;
use App\Services\SettingService;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

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
        try {
            Mail::build($config)->raw($bodyMarkdown, fn ($message) => $message->to($to)->from($this->settings->get('mail.from_address', 'noreply@example.test'))->subject($subject));
        } catch (UnexpectedResponseException $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                throw new RetryableNotificationException;
            }

            if ($e->getCode() >= 500 && $e->getCode() < 600) {
                return false;
            }
            throw new UncertainNotificationException;
        } catch (TransportExceptionInterface) {
            throw new UncertainNotificationException;
        }

        return true;
    }
}
