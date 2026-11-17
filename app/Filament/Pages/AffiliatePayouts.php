<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class AffiliatePayouts extends OperationsPage
{
    protected static ?string $slug = 'payouts';

    protected static ?string $title = '佣金转钱包审核';

    protected const SECTION = 'payouts';

    protected const PERMISSION = 'finance.view';
}
