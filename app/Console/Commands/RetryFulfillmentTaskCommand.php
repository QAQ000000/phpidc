<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RetryFulfillmentTaskAction;
use App\Models\FulfillmentTask;
use Illuminate\Console\Command;

class RetryFulfillmentTaskCommand extends Command
{
    protected $signature = 'fulfillment:retry {task} {--reason=}';

    protected $description = 'Audit and requeue a failed task as a trusted system operator';

    public function handle(RetryFulfillmentTaskAction $retry): int
    {
        $retry->execute(FulfillmentTask::findOrFail($this->argument('task')), $this->option('reason') ?? '');
        $this->info('Task queued.');

        return self::SUCCESS;
    }
}
