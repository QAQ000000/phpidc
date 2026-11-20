@extends('layouts.public')
@section('content')
<section class="hero">
    <div class="eyebrow">产品商城</div>
    <h1>{{ $selected?->name ?? '选择适合你的产品' }}</h1>
    <p class="lead">{{ $selected?->description ?? '了解产品能力与计费方式，确认后再创建订单。' }}</p>
</section>
<div class="store-catalog-layout">
    <details class="store-category-nav" open>
        <summary>产品分类</summary>
        <nav class="store-category-links" aria-label="产品分类">
            <a href="/store" @class(['selected' => $selected === null]) @if($selected === null) aria-current="page" @endif>全部产品</a>
            @foreach($categories as $category)
                <a href="/store/{{ rawurlencode($category->slug) }}" @class(['selected' => $selected?->id === $category->id]) @if($selected?->id === $category->id) aria-current="page" @endif>{{ $category->name }}</a>
            @endforeach
        </nav>
    </details>
    <section aria-labelledby="catalog-results-title">
        <div class="store-results-header">
            <h2 id="catalog-results-title">{{ $selected?->name ?? '全部产品' }}</h2>
            <span class="muted">共 {{ $products->total() }} 款产品</span>
        </div>
        @include('public.partials.products')
        {{ $products->links('public.pagination') }}
    </section>
</div>
@endsection
