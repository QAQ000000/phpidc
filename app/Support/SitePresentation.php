<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingService;

class SitePresentation
{
    /** @return array<string, string> */
    public function values(): array
    {
        $values = [];
        foreach (SiteSettings::fields() as $key => $field) {
            $value = app(SettingService::class)->get($key, $field['default']);
            $values[$key] = $value !== null && trim($value) !== '' ? $value : $field['default'];
        }

        return $values;
    }
}
