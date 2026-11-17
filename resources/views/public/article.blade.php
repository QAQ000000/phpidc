@extends('layouts.public')
@section('content')
<div class="breadcrumb"><a href="{{ $article->category->type->routePrefix() }}">{{ $article->category->type === \App\Enums\CmsArticleType::NEWS ? '新闻动态' : '帮助中心' }}</a> / <a href="{{ $article->category->publicPath() }}">{{ $article->category->name }}</a></div>
<article class="prose"><div class="eyebrow">{{ $article->category->name }}</div><h1>{{ $article->title }}</h1><p class="muted"><time datetime="{{ $article->published_at->toAtomString() }}">{{ $article->published_at->format('Y-m-d') }}</time> · <a href="{{ $article->publicPath() }}.md">Markdown 版本</a></p>
<div>{!! $body !!}</div>
</article>
<div class="actions"><a class="button secondary" href="{{ $article->category->publicPath() }}">返回分类</a><a class="button secondary" href="/store">浏览产品</a></div>
@endsection
