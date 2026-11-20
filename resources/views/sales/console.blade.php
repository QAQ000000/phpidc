@extends('sales.layout')
@section('title', '客户总览')
@section('content')
<x-console.page-header :title="$user->name.'，欢迎回来'" description="在这里查看服务、处理账单和管理账户。">
    <x-slot:actions><a class="console-button is-secondary" href="/console/tickets">提交工单</a><a class="console-button" href="/store">选购产品</a></x-slot:actions>
</x-console.page-header>
@if(!$user->email_verified_at)<p class="notice">邮箱待验证，请前往<a href="/console/account">账户安全</a>发送验证链接。</p>@endif
<div class="console-stats">
    <div class="console-stat is-highlight"><span>可用余额 / CNY</span><strong>{{ $user->credit }}</strong><small>冻结余额 {{ $user->frozen_credit }} CNY</small><br><a href="/console/wallet">管理钱包 →</a></div>
    <div class="console-stat"><span>待付款账单</span><strong>{{ $unpaidCount }}</strong><small>全部账单 {{ $invoiceCount }} 条</small><br><a href="/console/invoices?status=Unpaid">查看待付款 →</a></div>
    <div class="console-stat"><span>使用中服务</span><strong>{{ $activeCount }}</strong><small>全部服务 {{ $instanceCount }} 个</small><br><a href="/console/instances?status=Active">管理服务 →</a></div>
    <div class="console-stat"><span>账户安全</span><strong class="console-security-value">{{ $user->email_verified_at ? '邮箱已验证' : '邮箱待验证' }}</strong><small>{{ $user->email }}</small><br><a href="/console/account">安全设置 →</a></div>
</div>
<x-console.card title="最近服务" description="最近创建的 10 个服务">
    <x-slot:actions><a href="/console/instances">查看全部服务 →</a></x-slot:actions>
    @include('sales.instance-list')
</x-console.card>
<x-console.card title="最近账单" description="最近创建的 10 条账单">
    <x-slot:actions><a href="/console/invoices">查看全部账单 →</a></x-slot:actions>
    @include('sales.invoice-list')
</x-console.card>
<x-console.page-header title="精选商品" description="展示最多6款商品。">
    <x-slot:actions><a href="/store">浏览全部商品与分页目录 →</a></x-slot:actions>
</x-console.page-header>
@php
    $featuredProducts = $products->filter(static fn ($product) => $product->driver_type === \App\Enums\DriverType::ZJMF_API
        || (in_array($product->driver_type, [\App\Enums\DriverType::REDEMPTION, \App\Enums\DriverType::SUB2API], true) && ($product->local_prices['onetime']['enabled'] ?? false)));
@endphp
<div class="console-product-grid">
@forelse($featuredProducts as $product)
    <x-console.card :title="$product->name">
        @if($product->driver_type === \App\Enums\DriverType::ZJMF_API)
            <p class="console-product-description">选择配置查看本地预估，提交订单时核对上游价格与库存。</p>
            <p><a class="console-button is-secondary" href="/console/products/{{ $product->id }}/configure">选择配置与查看价格</a></p>
        @else
            <p class="console-product-price">{{ $product->local_prices['onetime']['price'] }} <small>{{ $product->currency }} / 次</small></p>
            <p class="console-muted">开通费 {{ $product->local_prices['onetime']['setup_fee'] ?? '0.00' }} {{ $product->currency }}</p>
            <details><summary>立即选购</summary>
                <form method="post" action="/checkout">@csrf
                    <label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64"></label>
                    <input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="billing_cycle" value="onetime">
                    @include('sales.purchase-request')
                    @if(($product->provisioning_rules['hostname_mode'] ?? '') === 'custom')<label>主机名 <input name="hostname" required></label>@endif
                    @if(isset($product->provisioning_rules['password_min_len']) || isset($product->provisioning_rules['password_max_len']) || isset($product->provisioning_rules['password_regex']))<label>开通密码 <input name="password" type="password" required autocomplete="new-password"></label>@endif
                    <label><input name="use_credit" type="checkbox" value="1" checked>使用钱包余额</label>
                    <label>支付渠道 <select name="gateway">@include('sales.payment-options')</select></label>
                    <button type="submit">创建订单</button>
                </form>
            </details>
        @endif
    </x-console.card>
@empty
    <p class="console-empty">暂无精选商品，<a href="/store">前往商城浏览全部商品</a>。</p>
@endforelse
</div>
@endsection
