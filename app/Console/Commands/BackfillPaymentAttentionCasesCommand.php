<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BackfillPaymentAttentionCasesAction;
use Illuminate\Console\Command;

class BackfillPaymentAttentionCasesCommand extends Command
{
    protected $signature = 'app:backfill-payment-attention-cases';

    protected $description = 'Register historical payment/refund attention sources without changing funds';

    public function handle(BackfillPaymentAttentionCasesAction $action): int
    {
        $this->line(json_encode($action->execute(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
