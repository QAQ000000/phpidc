<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OperationsCheck;
use Illuminate\Console\Command;

class CheckOperationsCommand extends Command
{
    protected $signature = 'app:operations-check {--json}';

    protected $description = 'Read-only independent database, Redis, maintenance and durable-failure observations';

    public function handle(OperationsCheck $check): int
    {
        $result = $check->execute();
        $this->line($this->option('json') ? json_encode($result, JSON_THROW_ON_ERROR)
            : strtoupper($result['status']).': '.implode(', ', array_map(fn (array $item): string => $item['key'].'='.$item['status'], $result['checks'])));

        return match ($result['status']) {
            'ok' => self::SUCCESS, 'warning' => self::FAILURE, default => 2,
        };
    }
}
