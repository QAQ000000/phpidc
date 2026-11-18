<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ExpireUnpaidInvoicesAction;
use Illuminate\Console\Command;

class ExpireUnpaidInvoicesCommand extends Command
{
    protected $signature = 'invoices:expire {--maintenance : Dispatch the bounded asynchronous maintenance batch}';

    protected $description = 'Expire unpaid invoices and release wallet holds';

    public function handle(ExpireUnpaidInvoicesAction $action): int
    {
        if ($this->option('maintenance')) {
            return $this->call('maintenance:dispatch', ['kind' => 'invoices']);
        }
        $action->execute();

        return self::SUCCESS;
    }
}
