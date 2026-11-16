<?php

declare(strict_types=1);

namespace App\Data;

enum ProvisionOutcome: string
{
    case PROVISIONED = 'provisioned';
    case RECONCILED = 'reconciled';
    case FAILED = 'failed';
}
