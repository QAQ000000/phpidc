<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class SupportTickets extends OperationsPage
{
    protected static ?string $slug = 'support';

    protected static ?string $title = '工单处理';

    protected const SECTION = 'support';

    protected const PERMISSION = 'support.view';
}
