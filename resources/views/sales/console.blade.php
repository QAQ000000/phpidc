@extends('sales.layout')
@section('title', '客户总览')
@section('content')
<h1>{{ $user->name }}，欢迎回来</h1><p>可用余额 {{ $user->credit }}；冻结余额 {{ $user->frozen_credit }}</p>
@if(!$user->email_verified_at)<p class="notice">邮箱待验证，请前往<a href="/console/account">账户安全</a>发送验证链接。</p>@endif
<div class="summary-grid"><section><h2>我的账单</h2><p>{{ $invoiceCount }} 条 · 待付款 {{ $unpaidCount }} 条</p><a href="/console/invoices">查看全部账单</a></section><section><h2>我的服务</h2><p>{{ $instanceCount }} 个 · 使用中 {{ $activeCount }} 个</p><a href="/console/instances">查看全部服务</a></section></div>
<h2>最近账单</h2>@include('sales.invoice-list')
<h2>最近服务</h2>@include('sales.instance-list')
<h2>精选商品</h2><p>展示最多6款商品。<a href="/store">浏览全部商品与分页目录</a></p>
@foreach($products as $product)
@if(in_array($product->driver_type, [\App\Enums\DriverType::REDEMPTION, \App\Enums\DriverType::SUB2API], true) && ($product->local_prices['onetime']['enabled'] ?? false))
<section><h3>{{ $product->name }}</h3><p>{{ $product->local_prices['onetime']['price'] }} {{ $product->currency }} / 次，开通费 {{ $product->local_prices['onetime']['setup_fee'] ?? '0.00' }}</p>
<form method="post" action="/checkout">@csrf<label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64"></label><input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="billing_cycle" value="onetime">
@include('sales.purchase-request')
@if(($product->provisioning_rules['hostname_mode'] ?? '') === 'custom')<label>主机名 <input name="hostname" required></label>@endif
@if(isset($product->provisioning_rules['password_min_len']) || isset($product->provisioning_rules['password_max_len']) || isset($product->provisioning_rules['password_regex']))<label>开通密码 <input name="password" type="password" required></label>@endif
<label><input name="use_credit" type="checkbox" value="1" checked>使用钱包余额</label><label>支付渠道 <select name="gateway">@include('sales.payment-options')</select></label><button>创建订单</button></form></section>
@endif
@if($product->driver_type === \App\Enums\DriverType::ZJMF_API)
<section><h3>{{ $product->name }}</h3><p>创建订单时获取实时价格，请先查看账单再付款。</p>
<p><a href="/console/products/{{ $product->id }}/configure">完整配置选项与实时报价</a></p>
</section>
@endif
@endforeach
@endsection
