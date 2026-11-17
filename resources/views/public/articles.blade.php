@extends('layouts.public')
@section('content')
<section class="hero"><div class="eyebrow">{{ $kind === \App\Enums\CmsArticleType::NEWS ? 'News & updates' : 'Help & guides' }}</div><h1>{{ $selected?->name ?? ($kind === \App\Enums\CmsArticleType::NEWS ? '新闻动态' : '帮助中心') }}</h1><p class="lead">{{ $description }}</p></section>
<nav class="filters" aria-label="内容分类"><a href="{{ $kind->routePrefix() }}" @class(['selected' => $selected === null])>全部内容</a>@foreach($categories as $category)<a href="{{ $category->publicPath() }}" @class(['selected' => $selected?->id === $category->id])>{{ $category->name }}</a>@endforeach</nav>
<form class="search" method="get"><label for="search">搜索文章标题<input id="search" name="q" maxlength="100" value="{{ $search }}" placeholder="输入关键词"></label><button>搜索</button></form>
@include('public.partials.articles')
<div class="pagination">{{ $articles->links() }}</div>
@endsection
