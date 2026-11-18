<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\QuoteResult;
use RuntimeException;

class PurchasePriceChanged extends RuntimeException
{
    public function __construct(public readonly QuoteResult $quote, public readonly string $quoteToken)
    {
        parent::__construct('核验后的价格需要确认，请检查周期价格和开通费，再确认创建账单。');
    }
}
