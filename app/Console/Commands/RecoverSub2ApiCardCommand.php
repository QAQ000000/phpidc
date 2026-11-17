<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecoverSub2ApiCardAction;
use App\Models\FulfillmentTask;
use Illuminate\Console\Command;

class RecoverSub2ApiCardCommand extends Command
{
    protected $signature = 'sub2api:recover {task} {code_id} {--confirmed} {--reason=}';

    protected $description = 'Read and recover an operator-confirmed upstream code without generating a new one';

    public function handle(RecoverSub2ApiCardAction $recover): int
    {
        $recover->execute(FulfillmentTask::findOrFail($this->argument('task')), (int) $this->argument('code_id'), $this->option('reason') ?? '', (bool) $this->option('confirmed'));
        $this->info('Existing code verified; the same task is queued for delivery.');

        return self::SUCCESS;
    }
}
