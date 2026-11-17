<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SaveCmsArticleAction;
use App\Models\CmsCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SaveCmsArticleCommand extends Command
{
    protected $signature = 'cms:article {category} {slug} {title} {file} {--format=markdown} {--publish} {--publish-at=} {--seo-title=} {--seo-description=} {--reason=}';

    protected $description = 'Save an article from a UTF-8 file; draft unless --publish is supplied';

    public function handle(SaveCmsArticleAction $save): int
    {
        $article = $save->execute(CmsCategory::findOrFail($this->argument('category')), ['slug' => $this->argument('slug'), 'title' => $this->argument('title'),
            'body' => File::get($this->argument('file')), 'content_format' => $this->option('format'), 'is_published' => $this->option('publish'),
            'published_at' => $this->option('publish-at'), 'seo_title' => $this->option('seo-title'), 'seo_description' => $this->option('seo-description')], $this->option('reason') ?? '');
        $this->info("Saved article {$article->id}: ".($article->is_published ? 'published/scheduled' : 'draft'));

        return self::SUCCESS;
    }
}
