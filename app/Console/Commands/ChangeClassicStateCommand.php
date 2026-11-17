<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SuspendInstanceAction;
use App\Actions\UnsuspendInstanceAction;
use App\Models\Instance;
use DomainException;
use Illuminate\Console\Command;

class ChangeClassicStateCommand extends Command
{
    protected $signature = 'upstream:classic-state {instance} {operation} {--reason=}';

    protected $description = 'Pre-sync and suspend or unsuspend an instance as an audited system operator';

    public function handle(): int
    {
        $action = match ($this->argument('operation')) {
            'suspend' => app(SuspendInstanceAction::class),
            'unsuspend' => app(UnsuspendInstanceAction::class),
            default => throw new DomainException('Operation must be suspend or unsuspend.'),
        };
        $result = $action->execute(Instance::findOrFail($this->argument('instance')), $this->option('reason') ?? '');
        if (! $result->success) {
            $this->error($result->errorMessage ?? 'Lifecycle change failed.');

            return self::FAILURE;
        }
        $this->info('Instance state reconciled.');

        return self::SUCCESS;
    }
}
