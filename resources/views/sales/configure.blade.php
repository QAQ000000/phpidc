@extends('sales.layout')
@section('content')
<h1>配置 {{ $product->name }}</h1><p>选择配置后查看实时价格，再确认账单。价格与库存以创建账单时的核对结果为准。</p>
<form method="post" action="/checkout" id="classic-configuration" data-quote-url="/console/products/{{ $product->id }}/quote">@csrf<label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64"></label>
<input type="hidden" name="product_id" value="{{ $product->id }}">
@include('sales.purchase-request')
<label>计费周期<select name="billing_cycle">@foreach($details['cycles'] as $cycle)<option value="{{ $cycle['value'] }}" @selected(($selection['billing_cycle'] ?? '') === $cycle['value'])>{{ $cycle['label'] }}</option>@endforeach</select></label>
@include('sales.config-options')
<p id="classic-price" role="status">@if($quote)实时价格：{{ $quote->downstreamPrice }} {{ $quote->currency }}；开通费 {{ $quote->setupFee }}@else选择配置后点击实时报价@endif</p>
<button type="submit" formaction="/console/products/{{ $product->id }}/quote" formnovalidate>实时报价</button>
<label>主机名<input name="hostname" autocomplete="off"></label>
<label>开通密码<input name="password" type="password" autocomplete="new-password"></label>
<p>主机名与密码须同时符合商品规则及上游规则。</p>
<label><input name="use_credit" type="checkbox" value="1">使用钱包余额（余额足够时立即结算）</label>
<label>其余金额支付方式<select name="gateway"><option value="epay_wechat">微信</option><option value="epay_alipay">支付宝</option></select></label>
<button type="submit">核价并创建账单</button>
</form>
<script type="application/json" id="classic-dependencies">{!! json_encode($product->provisioning_rules['classic_catalog']['dependencies'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="/js/classic-config.js" defer></script>
@endsection
