<?php

declare(strict_types=1);

namespace App\Filament\Pages;

class PromotionCoupons extends OperationsPage
{
    protected static ?string $slug = 'coupons';

    protected static ?string $title = '优惠券运营';

    protected const SECTION = 'coupons';

    protected const PERMISSION = 'promotion.view';
}
