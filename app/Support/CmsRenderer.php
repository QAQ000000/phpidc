<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CmsContentFormat;
use App\Models\CmsArticle;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CmsRenderer
{
    public function sanitize(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set('HTML.Allowed', 'p,br,strong,em,ul,ol,li,blockquote,h2,h3,h4,pre,code,a[href|title],img[src|alt|title],table,thead,tbody,tr,th,td,hr');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        return (new HTMLPurifier($config))->purify($html);
    }

    public function html(CmsArticle $article): string
    {
        $html = $article->content_format === CmsContentFormat::HTML ? $article->body : Str::markdown($article->body,
            ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return $this->sanitize($html);
    }

    public function markdown(CmsArticle $article): string
    {
        return '# '.$article->title."\n\n".($article->content_format === CmsContentFormat::HTML ? $this->html($article) : $article->body)."\n";
    }

    public function summary(string $body): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''), 160);
    }

    public function excerpt(CmsArticle $article, ?string $safeHtml = null): string
    {
        // Increment the rendering version when Markdown, sanitizer or summary rules change.
        $key = 'cms-excerpt:v1:'.$article->content_format->value.':'.hash('sha256', $article->body);

        return Cache::remember($key, 86400, fn () => $this->summary($safeHtml ?? $this->html($article)));
    }
}
