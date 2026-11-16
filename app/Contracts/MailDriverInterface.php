<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Mail had no driver contract at all, while the notification fan-out assumed
 * one (charter §3.7). Mail is a first-class channel and needs the same
 * abstraction as bot and sms.
 */
interface MailDriverInterface
{
    /** @return bool Whether the message was accepted by the transport. */
    public function send(string $to, string $subject, string $bodyMarkdown, array $variables = []): bool;
}
