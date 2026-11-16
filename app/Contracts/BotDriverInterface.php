<?php

declare(strict_types=1);

namespace App\Contracts;

interface BotDriverInterface
{
    public function send(string $target, string $markdown): bool;
}
