@extends('layouts.public')
@section('content')
<section class="hero"><div class="eyebrow">Product catalog</div><h1>{{ $selected?->name ?? '选择适合你的产品' }}</h1><p class="lead">{{ $selected?->description ?? '了解产品能力与计费方式，确认后再创建订单。' }}</p></section>
<nav class="filters" aria-label="产品分类"><a href="/store" @class(['selected' => $selected === null])>全部产品</a>@foreach($categories as $category)<a href="/store/{{ $category->slug }}" @class(['selected' => $selected?->id === $category->id])>{{ $category->name }}</a>@endforeach</nav>
@include('public.partials.products')
<div class="pagination">{{ $products->links() }}</div>
@endsection
