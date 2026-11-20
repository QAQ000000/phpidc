@php($sitePresentation = app(\App\Support\SitePresentation::class)->values())
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<title>{{ $title }} · {{ $siteName }}</title>
<meta name="description" content="{{ $description }}">
@if($keywords)<meta name="keywords" content="{{ $keywords }}">@endif
@if($noindex ?? false)<meta name="robots" content="noindex,follow">@endif
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:title" content="{{ $title }}"><meta property="og:description" content="{{ $description }}"><meta property="og:url" content="{{ $canonical }}"><meta property="og:type" content="{{ isset($article) ? 'article' : 'website' }}">
@if(isset($article) || isset($product))<link rel="alternate" type="text/markdown" href="{{ $canonical }}.md" title="Markdown">@endif
<link rel="stylesheet" href="/css/storefront.css?v=20261120">
</head>
<body class="storefront-app">
<a class="store-skip-link" href="#store-main">跳至主要内容</a>
<header class="store-header"><div class="wrap"><a class="brand" href="/">@if($sitePresentation['site.logo_url'] !== '')<img src="{{ $sitePresentation['site.logo_url'] }}" alt="" width="36" height="36" style="display:inline-block;vertical-align:middle;object-fit:contain"> @endif{{ $siteName }}</a><nav aria-label="主导航">
<a href="/store" @if(request()->is('store*')) aria-current="page" @endif>产品商城</a><a href="/news" @if(request()->is('news*')) aria-current="page" @endif>新闻动态</a><a href="/help" @if(request()->is('help*')) aria-current="page" @endif>帮助中心</a>@if($sitePresentation['site.support_url'] !== '')<a href="{{ $sitePresentation['site.support_url'] }}" rel="noopener noreferrer">联系客服</a>@endif<a class="store-console-link" href="{{ auth()->check() ? '/console' : '/login' }}">{{ auth()->check() ? '客户中心' : '登录 / 注册' }}</a>
</nav></div></header>
<main class="wrap" id="store-main" tabindex="-1">
@if($sitePresentation['site.announcement'] !== '')<aside class="panel store-announcement" role="status">{{ $sitePresentation['site.announcement'] }}</aside>@endif
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main>
<footer><div class="wrap"><span>{{ $sitePresentation['site.copyright'] !== '' ? $sitePresentation['site.copyright'] : $siteName.' · 产品与知识，触手可及。' }}</span>@if($sitePresentation['site.icp_number'] !== '')<a href="https://beian.miit.gov.cn/" rel="noopener noreferrer">{{ $sitePresentation['site.icp_number'] }}</a>@endif<span><a href="/sitemap.xml">站点地图</a> · <a href="/llms.txt">AI 阅读索引</a></span></div></footer>
</body></html>
