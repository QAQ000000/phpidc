<?php

declare(strict_types=1);

namespace App\Enums;

/** Internal result vocabulary; never a replacement for the original callback outcome. */
enum PaymentPostingKind: string
{
    case INVOICE_SETTLEMENT = 'invoice_settlement';
    case WALLET_TOPUP = 'wallet_topup';
    case WALLET_RECEIPT = 'wallet_receipt';
}
