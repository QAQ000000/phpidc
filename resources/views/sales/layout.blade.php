@php
    $sitePresentation = app(\App\Support\SitePresentation::class)->values();
    $pageTitle = trim($__env->yieldContent('title', '客户中心')) ?: '客户中心';
    $isAuthPage = request()->is('login', 'register', 'forgot-password', 'reset-password/*', 'auth/telegram/widget');
    $navigationGroups = [
        ['label' => null, 'items' => [
            ['label' => '客户总览', 'url' => '/console', 'active' => request()->is('console'), 'icon' => 'M3 10l9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z'],
            ['label' => '我的服务', 'url' => '/console/instances', 'active' => request()->is('console/instances*'), 'icon' => 'M4 3h16v7H4zM4 14h16v7H4zM8 6h.01M8 17h.01'],
            ['label' => '选购产品', 'url' => '/store', 'active' => request()->is('console/products*'), 'icon' => 'M3 3h2l3 12h11l2-8H6M9 21h.01M18 21h.01'],
        ]],
        ['label' => '财务管理', 'items' => [
            ['label' => '我的账单', 'url' => '/console/invoices', 'active' => request()->is('console/invoices*'), 'icon' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6'],
            ['label' => '我的钱包', 'url' => '/console/wallet', 'active' => request()->is('console/wallet*', 'console/receivable-checks*'), 'icon' => 'M20 8V5H4v15h16V8H4M16 11h5v5h-5z'],
            ['label' => '推广佣金', 'url' => '/console/affiliate', 'active' => request()->is('console/affiliate*'), 'icon' => 'M4 17l6-6 4 4 6-10M14 5h6v6'],
        ]],
        ['label' => '账户管理', 'items' => [
            ['label' => '账户安全', 'url' => '/console/account', 'active' => request()->is('console/account*', 'email/*', 'forgot-password', 'reset-password/*'), 'icon' => 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM8 12l3 3 5-6'],
            ['label' => '我的优惠券', 'url' => '/console/coupons', 'active' => request()->is('console/coupons*'), 'icon' => 'M3 7h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4zM15 7v12'],
        ]],
        ['label' => '帮助与支持', 'items' => [
            ['label' => '我的工单', 'url' => '/console/tickets', 'active' => request()->is('console/tickets*'), 'icon' => 'M4 4h16v12H9l-5 4zM8 8h8M8 12h5'],
            ['label' => '下载中心', 'url' => '/downloads', 'active' => request()->is('downloads*'), 'icon' => 'M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5'],
            ['label' => '帮助中心', 'url' => '/help', 'active' => request()->is('help*'), 'icon' => 'M9 8a3 3 0 1 1 5 2c-1 1-2 1-2 3M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0'],
        ]],
    ];
@endphp
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $pageTitle }} · {{ $sitePresentation['site.name'] }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/css/customer-console.css?v=20261120">
    @unless(request()->is('admin/content/articles/*/preview'))
        <script src="/js/customer-console.js?v=20261120" defer></script>
    @endunless
</head>
<body class="customer-app {{ auth()->check() ? 'console-signed-in' : 'console-guest' }} {{ $isAuthPage ? 'console-auth' : '' }}">
<a class="console-skip-link" href="#console-main">跳至主要内容</a>
@auth
    <aside id="console-sidebar" class="console-sidebar" aria-label="客户中心导航">
        <button type="button" id="console-nav-close" class="console-nav-close" aria-label="关闭导航">×</button>
        <a class="console-brand" href="/console">
            @if($sitePresentation['site.logo_url'] !== '')<img src="{{ $sitePresentation['site.logo_url'] }}" alt="" width="34" height="34">@else<span class="console-brand-mark" aria-hidden="true">云</span>@endif
            <span>{{ $sitePresentation['site.name'] }}<small>客户中心</small></span>
        </a>
        @include('sales.navigation')
        <div class="console-sidebar-footer">
            @if($sitePresentation['site.support_url'] !== '')<a href="{{ $sitePresentation['site.support_url'] }}" rel="noopener noreferrer">联系客服 <span aria-hidden="true">↗</span></a>@endif
            <form method="post" action="/logout">@csrf<button type="submit">退出登录</button></form>
        </div>
    </aside>
    <button type="button" class="console-nav-backdrop" id="console-nav-backdrop" aria-label="关闭导航" hidden></button>
    <header class="console-topbar">
        <div class="console-topbar-title"><button type="button" class="console-menu-toggle" id="console-menu-toggle" aria-controls="console-sidebar" aria-expanded="false" aria-label="打开导航"><span class="console-menu-icon" aria-hidden="true"></span></button><span>客户中心 <span class="console-breadcrumb-divider">/</span> <strong>{{ $pageTitle }}</strong></span></div>
        <a class="console-user" href="/console/account"><span class="console-avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span>{{ auth()->user()->name }}</span></a>
    </header>
@else
    <header class="console-guest-header"><a class="console-brand" href="/">@if($sitePresentation['site.logo_url'] !== '')<img src="{{ $sitePresentation['site.logo_url'] }}" alt="" width="34" height="34">@else<span class="console-brand-mark" aria-hidden="true">云</span>@endif<span>{{ $sitePresentation['site.name'] }}</span></a><nav aria-label="站点导航"><a href="/store">产品商城</a><a href="/help">帮助中心</a><a href="/downloads">下载中心</a>@if($sitePresentation['site.support_url'] !== '')<a href="{{ $sitePresentation['site.support_url'] }}" rel="noopener noreferrer">联系客服</a>@endif<a href="/login" @if(request()->is('login')) aria-current="page" @endif>登录</a><a class="console-button is-secondary" href="/register">注册</a></nav></header>
@endauth
<main id="console-main" class="console-main" tabindex="-1">
    @if($sitePresentation['site.announcement'] !== '')<aside class="notice console-announcement" role="status">{{ $sitePresentation['site.announcement'] }}</aside>@endif
    @if(session('status'))<p class="console-feedback is-success" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())<div class="console-feedback error" role="alert"><strong>请检查以下内容</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($errors->has('receivable_check_url'))<p><a href="{{ $errors->first('receivable_check_url') }}">查看原请求完整核验进度</a></p>@endif
    <div class="console-content">@yield('content')</div>
    <footer class="console-footer"><span>{{ $sitePresentation['site.copyright'] !== '' ? $sitePresentation['site.copyright'] : $sitePresentation['site.name'] }}</span>@if($sitePresentation['site.icp_number'] !== '')<a href="https://beian.miit.gov.cn/" rel="noopener noreferrer">{{ $sitePresentation['site.icp_number'] }}</a>@endif</footer>
</main>
</body>
</html>
