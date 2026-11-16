<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Instance;

interface HasCustomPanelWidgets
{
    /** @return list<array{key: string, title: string, type: string, data: mixed}> */
    public function fetchPanelWidgets(Instance $instance): array;
}
