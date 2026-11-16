<?php

declare(strict_types=1);

namespace App\Enums;

enum CmsArticleType: string
{
    case NEWS = 'news';
    case HELP = 'help';

    public function routePrefix(): string
    {
        return match ($this) {
            self::NEWS => '/news',
            self::HELP => '/help',
        };
    }
}
