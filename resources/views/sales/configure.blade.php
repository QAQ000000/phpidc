@extends('sales.layout')
@section('content')
<h1>配置 {{ $product->name }}</h1><p>选择配置查看本地预估，最终以提交核验为准。提交时实时核对上游价格与库存；价格变化后须再次确认。</p>
<p>以下显示商品周期价与开通费，优惠券在创建账单时计算。</p>
<form method="post" action="/checkout" id="classic-configuration" data-quote-url="/console/products/{{ $product->id }}/quote" data-confirmation-required="{{ ($confirmationRequired ?? false) ? '1' : '0' }}">@csrf<label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64" value="{{ $checkoutInput['coupon_code'] ?? '' }}"></label>
<input type="hidden" name="product_id" value="{{ $product->id }}">
<input type="hidden" name="quote_token" value="{{ $quoteToken ?? '' }}">
@include('sales.purchase-request')
<label>计费周期<select name="billing_cycle">@foreach($details['cycles'] as $cycle)<option value="{{ $cycle['value'] }}" @selected(($selection['billing_cycle'] ?? '') === $cycle['value'])>{{ $cycle['label'] }}</option>@endforeach</select></label>
@include('sales.config-options')
<p id="classic-price" role="status" aria-live="polite">@if($quote){{ ($confirmationRequired ?? false) ? '上游核验价格' : '本地预估价格' }}：{{ $quote->downstreamPrice }} {{ $quote->currency }}；开通费 {{ $quote->setupFee }}。{{ ($confirmationRequired ?? false) ? '请确认新价格后创建账单。' : '本地预估，最终以提交核验为准。' }}@else选择配置后查看本地预估价格，最终以提交核验为准。@endif</p>
<button type="submit" formaction="/console/products/{{ $product->id }}/quote" data-quote-trigger formnovalidate>更新预估价格</button>
<label>主机名<input name="hostname" autocomplete="off" value="{{ $checkoutInput['hostname'] ?? '' }}"></label>
<label>开通密码<input name="password" type="password" autocomplete="new-password"></label>
<p>主机名与密码须同时符合商品规则及上游规则。</p>
@if($confirmationRequired ?? false)<p>请重新填写开通密码与自定义字段，再确认新价格。</p>@endif
<label><input name="use_credit" type="checkbox" value="1" @checked($checkoutInput['use_credit'] ?? false)>使用钱包余额（余额足够时立即结算）</label>
<label>其余金额支付方式<select name="gateway"><option value="epay_wechat" @selected(($checkoutInput['gateway'] ?? 'epay_wechat') === 'epay_wechat')>微信</option><option value="epay_alipay" @selected(($checkoutInput['gateway'] ?? '') === 'epay_alipay')>支付宝</option></select></label>
<button type="submit" data-checkout-trigger>{{ ($confirmationRequired ?? false) ? '确认新价格并创建账单' : '核价并创建账单' }}</button>
</form>
<script type="application/json" id="classic-dependencies">{!! json_encode($product->provisioning_rules['classic_catalog']['dependencies'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
<script src="/js/classic-config.js" defer></script>
@endsection
