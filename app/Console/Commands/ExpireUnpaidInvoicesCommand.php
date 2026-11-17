<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExpireUnpaidInvoicesAction;
use Illuminate\Console\Command;

class ExpireUnpaidInvoicesCommand extends Command
{
    protected $signature = 'invoices:expire';

    protected $description = 'Expire unpaid invoices and release wallet holds';

    public function handle(ExpireUnpaidInvoicesAction $action): int
    {
        $action->execute();

        return self::SUCCESS;
    }
}
