<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BackfillExternalPayoutReceiptsAction;
use Illuminate\Console\Command;

class BackfillExternalPayoutReceiptsCommand extends Command
{
    protected $signature = 'payments:backfill-payout-receipts {--dry-run : Inspect without inserting registry rows} {--json : Emit the structured report}';

    protected $description = 'Register historical confirmed payouts without changing money; report every conflict';

    public function handle(BackfillExternalPayoutReceiptsAction $backfill): int
    {
        $report = $backfill->execute((bool) $this->option('dry-run'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Scanned '.$report['scanned'].'; registered '.$report['registered'].'; existing '.$report['existing'].'; would register '.$report['would_register'].'.');
            foreach ($report['conflicts'] as $conflict) {
                $this->error($conflict['kind'].':'.$conflict['id'].' ['.$conflict['receipt_fingerprint'].'] '.$conflict['reason']);
            }
        }

        return $report['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
