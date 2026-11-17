<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SaveCmsCategoryAction;
use Illuminate\Console\Command;

class SaveCmsCategoryCommand extends Command
{
    protected $signature = 'cms:category {type} {slug} {name} {--hidden} {--seo-title=} {--seo-description=} {--reason=}';

    protected $description = 'Create or update a news/help category as an audited system operator';

    public function handle(SaveCmsCategoryAction $save): int
    {
        $category = $save->execute(['type' => $this->argument('type'), 'slug' => $this->argument('slug'), 'name' => $this->argument('name'),
            'is_hidden' => $this->option('hidden'), 'seo_title' => $this->option('seo-title'), 'seo_description' => $this->option('seo-description')], $this->option('reason') ?? '');
        $this->info("Saved category {$category->id}: {$category->publicPath()}");

        return self::SUCCESS;
    }
}
