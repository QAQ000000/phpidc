<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class DownloadOperations extends OperationsPage
{
    protected static ?string $slug = 'downloads';

    protected static ?string $title = '下载资源';

    protected const SECTION = 'downloads';

    protected const PERMISSION = 'download.view';
}
