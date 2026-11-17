@extends('sales.layout')
@section('content')
<p>内部预览 · <a href="/admin/content?article={{ $article->id }}">返回编辑</a></p>
<h1>{{ $article->title }}</h1><p>{{ $article->is_published ? ($article->published_at?->isFuture() ? '预约发布' : '已发布') : '草稿' }}</p>
<article>{!! $html !!}</article>
@endsection
