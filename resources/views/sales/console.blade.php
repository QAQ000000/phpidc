@extends('sales.layout')
@section('content')
<h1>{{ $user->name }}，欢迎回来</h1><p>可用余额 {{ $user->credit }}；冻结余额 {{ $user->frozen_credit }}</p>
<h2>商品</h2>
@foreach($products as $product)
@if($product->driver_type === \App\Enums\DriverType::REDEMPTION && ($product->local_prices['onetime']['enabled'] ?? false))
<section><h3>{{ $product->name }}</h3><p>{{ $product->local_prices['onetime']['price'] }} {{ $product->currency }} / 次，开通费 {{ $product->local_prices['onetime']['setup_fee'] ?? '0.00' }}</p>
<form method="post" action="/checkout">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}"><input type="hidden" name="billing_cycle" value="onetime">
@if(($product->provisioning_rules['hostname_mode'] ?? '') === 'custom')<label>主机名 <input name="hostname" required></label>@endif
@if(isset($product->provisioning_rules['password_min_len']) || isset($product->provisioning_rules['password_max_len']) || isset($product->provisioning_rules['password_regex']))<label>开通密码 <input name="password" type="password" required></label>@endif
<label><input name="use_credit" type="checkbox" value="1" checked>使用钱包余额</label><label>支付渠道 <select name="gateway"><option value="epay_wechat">微信</option><option value="epay_alipay">支付宝</option></select></label><button>创建订单</button></form></section>
@endif
@if($product->driver_type === \App\Enums\DriverType::ZJMF_API)
<section><h3>{{ $product->name }}</h3><p>创建订单时获取实时价格，请先查看账单再付款。</p>
<form method="post" action="/checkout">@csrf<input type="hidden" name="product_id" value="{{ $product->id }}">
<label>计费周期 <select name="billing_cycle">@foreach(($product->upstream_base_prices ?? []) as $cycle => $price)@if($price['enabled'] ?? false)<option value="{{ $cycle }}">{{ $cycle }}</option>@endif@endforeach</select></label>
<label>主机名 <input name="hostname"></label><label>开通密码 <input name="password" type="password" required autocomplete="new-password"></label>
<label>支付渠道 <select name="gateway"><option value="epay_wechat">微信</option><option value="epay_alipay">支付宝</option></select></label><button>获取价格并创建账单</button></form></section>
@endif
@endforeach
<h2>我的账单</h2><ul>@forelse($invoices as $invoice)<li><a href="/console/invoices/{{ $invoice->id }}">{{ $invoice->invoice_no }}</a> {{ $invoice->status->value }} / {{ $invoice->total_amount }} {{ $invoice->currency }}</li>@empty<li>暂无账单</li>@endforelse</ul>
<h2>我的服务</h2><ul>@forelse($instances as $instance)<li><a href="/console/instances/{{ $instance->id }}">{{ $instance->display_name }}</a> {{ $instance->status->value }}</li>@empty<li>暂无交付</li>@endforelse</ul>
@endsection
