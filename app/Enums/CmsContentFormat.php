<?php

declare(strict_types=1);

namespace App\Enums;

enum CmsContentFormat: string
{
    case MARKDOWN = 'markdown';
    case HTML = 'html';

    /** HTML is sanitized through HTMLPurifier before rendering (Phase 2). */
    public function requiresSanitization(): bool
    {
        return $this === self::HTML;
    }
}
