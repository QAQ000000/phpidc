<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class NotificationOperations extends OperationsPage
{
    protected static ?string $slug = 'notifications';

    protected static ?string $title = '通知模板与日志';

    protected const SECTION = 'notifications';

    protected const PERMISSION = 'notification.view';
}
