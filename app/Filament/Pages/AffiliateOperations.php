<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class AffiliateOperations extends OperationsPage
{
    protected static ?string $slug = 'affiliates';

    protected static ?string $title = '推广规则与佣金';

    protected const SECTION = 'affiliates';

    protected const PERMISSION = 'affiliate.view';
}
