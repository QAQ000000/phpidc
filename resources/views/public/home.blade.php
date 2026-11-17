@extends('layouts.public')
@section('content')
<section class="hero"><div class="eyebrow">Cloud products / Knowledge base</div><h1>找到所需产品，<br>让下一步更简单。</h1><p class="lead">从云服务到 API 充值卡，浏览产品、了解价格，也在这里找到使用中的答案。</p><div class="actions"><a class="button" href="/store">浏览产品</a><a class="button secondary" href="/help">阅读使用指南</a></div></section>
<div class="section-title"><h2>探索产品</h2><a href="/store">全部产品 →</a></div>@include('public.partials.products')
<div class="section-title"><h2>动态与指南</h2><a href="/news">阅读更多 →</a></div>@include('public.partials.articles')
@endsection
