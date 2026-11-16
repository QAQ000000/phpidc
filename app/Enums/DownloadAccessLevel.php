<?php

declare(strict_types=1);

namespace App\Enums;

enum DownloadAccessLevel: string
{
    case PUBLIC = 'public';
    case AUTHENTICATED = 'authenticated';
    case ACTIVE_CUSTOMER = 'active_customer';

    public function rank(): int
    {
        return match ($this) {
            self::PUBLIC => 0,
            self::AUTHENTICATED => 1,
            self::ACTIVE_CUSTOMER => 2,
        };
    }
}
