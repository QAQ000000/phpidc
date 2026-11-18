<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BackfillBindingOrphanCasesAction;
use Illuminate\Console\Command;

class BackfillBindingOrphanCasesCommand extends Command
{
    protected $signature = 'app:backfill-binding-orphan-cases';

    protected $description = 'Register historical retained upstream bindings without changing their disposition';

    public function handle(BackfillBindingOrphanCasesAction $action): int
    {
        $this->line(json_encode(['registered_or_verified' => $action->execute()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
