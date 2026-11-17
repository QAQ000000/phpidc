<div class="grid">
@forelse($articles as $article)
<article class="card"><span class="eyebrow">{{ $article->category->name }} · <time datetime="{{ $article->published_at->toAtomString() }}">{{ $article->published_at->format('Y-m-d') }}</time></span>
<h3><a href="{{ $article->publicPath() }}">{{ $article->title }}</a></h3><p>{{ $article->seo_description ?? app(\App\Support\CmsRenderer::class)->excerpt($article) }}</p>
<div class="more"><a href="{{ $article->publicPath() }}">继续阅读 →</a></div></article>
@empty<div class="empty">暂无已发布内容。</div>@endforelse
</div>
