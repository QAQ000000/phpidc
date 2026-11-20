@extends('sales.layout')
@section('title', '配置 '.$product->name)
@section('styles')
<link rel="stylesheet" href="/css/storefront.css?v=20261120">
@endsection
@section('content')
@php
    $generated = $provisioningDefaults ?? ['hostname' => null, 'password' => null, 'errors' => []];
    $hostRequired = $details['live'] || ($product->provisioning_rules['hostname_mode'] ?? null) === 'custom';
    $passwordRequired = $details['live'] || isset($product->provisioning_rules['password_min_len']) || isset($product->provisioning_rules['password_max_len']) || isset($product->provisioning_rules['password_regex']);
    $requiresLinkage = $product->optionGroups()->where('is_hidden', false)->where('sync_source', 'upstream')->where('upstream_meta->option_type', 20)->exists();
@endphp
<x-console.page-header :title="'配置 '.$product->name" description="选择配置查看本地预估，最终以提交核验为准。提交时实时核对上游价格与库存；价格变化后须再次确认。">
    <x-slot:actions><a class="console-button is-secondary" href="{{ $product->publicPath() }}">返回商品详情</a></x-slot:actions>
</x-console.page-header>
@if($details['purchase_unavailable'] ?? null)
    <x-console.card title="暂不可购买"><p role="alert">{{ $details['purchase_unavailable'] }}</p></x-console.card>
@elseif($details['stock'] === 0 && $details['live'])
    <x-console.card title="商品已售罄"><p role="status">最近同步显示售罄，请稍后再来。库存可能变化，恢复后仍会在提交时重新核验。</p></x-console.card>
@elseif($details['cycles'] === [])
    <x-console.card title="暂无可选周期"><p role="status">暂无可购买的计费周期。</p></x-console.card>
@else
<form method="post" action="/checkout" id="classic-configuration" class="store-configuration-layout" data-quote-url="/console/products/{{ $product->id }}/quote" data-confirmation-required="{{ ($confirmationRequired ?? false) ? '1' : '0' }}">@csrf
    <input type="hidden" name="product_id" value="{{ $product->id }}">
    <input type="hidden" name="quote_token" value="{{ $quoteToken ?? '' }}">
    @include('sales.purchase-request')
    <div class="store-configuration-main">
        <x-console.card title="计费周期" description="周期价与一次性开通费分开显示，优惠券在创建账单时计算。">
            <label>计费周期<select name="billing_cycle">@foreach($details['cycles'] as $cycle)<option value="{{ $cycle['value'] }}" @selected(($selection['billing_cycle'] ?? '') === $cycle['value'])>{{ $cycle['label'] }}</option>@endforeach</select></label>
            @if($details['live'] && $details['stock'] !== null)<p class="console-muted">最近同步库存：{{ $details['stock'] }}；提交时重新核验。</p>@endif
        </x-console.card>
        <x-console.card title="配置与补充资料" description="按需选择产品配置，并填写商品要求的资料。">
            @include('sales.config-options')
        </x-console.card>
        <x-console.card title="主机开通资料" description="主机名与开通密码按产品配置生成，也可按规则调整。">
            <div class="console-form-grid">
                <label>主机名<input name="hostname" autocomplete="off" value="{{ $checkoutInput['hostname'] ?? $generated['hostname'] ?? '' }}" @required($hostRequired)></label>
                <label>开通密码<input name="password" type="password" autocomplete="new-password" value="{{ $generated['password'] ?? '' }}" @required($passwordRequired)></label>
            </div>
            @if($generated['errors'])
                @foreach($generated['errors'] as $field => $message)<p role="alert">{{ $message }}</p>@endforeach
            @else<p class="console-muted">主机名与开通密码已按产品规则自动生成，提交时会重新核验。</p>@endif
            @if($confirmationRequired ?? false)<p class="notice">{{ $generated['password'] !== null ? '开通密码已重新生成，请检查并补充自定义字段，再确认新价格。' : '请填写开通密码与自定义字段，再确认新价格。' }}</p>@endif
        </x-console.card>
    </div>
    <aside class="store-order-summary" aria-label="下单汇总">
        <x-console.card title="下单汇总">
            <p class="store-summary-product"><strong>{{ $product->name }}</strong></p>
            <dl class="store-selection-summary"><dt>计费周期</dt><dd data-store-cycle>{{ collect($details['cycles'])->firstWhere('value', $selection['billing_cycle'] ?? $details['cycles'][0]['value'])['label'] ?? '未选择' }}</dd></dl>
            <dl class="store-selection-summary" data-store-selection-summary><dt>配置选项</dt><dd>完成选择后显示活动配置。</dd></dl>
            <p id="classic-price" class="store-price-status" role="status" aria-live="polite">@if($quote){{ ($confirmationRequired ?? false) ? '上游核验价格' : '本地预估价格' }}：{{ $quote->downstreamPrice }} {{ $quote->currency }}；开通费 {{ $quote->setupFee }}。{{ ($confirmationRequired ?? false) ? '请确认新价格后创建账单。' : '本地预估，最终以提交核验为准。' }}@else选择配置后查看本地预估价格，最终以提交核验为准。@endif</p>
            <div class="store-summary-payments">
                <label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64" value="{{ $checkoutInput['coupon_code'] ?? '' }}"></label>
                <label><input name="use_credit" type="checkbox" value="1" @checked($checkoutInput['use_credit'] ?? false)>使用钱包余额（余额足够时立即结算）</label>
                <label>其余金额支付方式<select name="gateway">@include('sales.payment-options', ['selectedGateway' => $checkoutInput['gateway'] ?? null])</select></label>
            </div>
            <div class="store-summary-buttons">
                <button type="submit" class="is-secondary" formaction="/console/products/{{ $product->id }}/quote" data-quote-trigger formnovalidate @disabled($requiresLinkage)>更新预估价格</button>
                <button type="submit" data-checkout-trigger @disabled($requiresLinkage)>{{ ($confirmationRequired ?? false) ? '确认新价格并创建账单' : '核价并创建账单' }}</button>
            </div>
            <p class="console-muted">以下显示商品周期价与开通费，优惠券在创建账单时计算。</p>
        </x-console.card>
    </aside>
</form>
<script type="application/json" id="classic-dependencies">{!! json_encode($product->provisioning_rules['classic_catalog']['dependencies'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endif
@endsection
@section('scripts')
@if(!($details['purchase_unavailable'] ?? null) && !($details['stock'] === 0 && $details['live']) && $details['cycles'] !== [])
<script src="/js/classic-config.js" defer></script>
<script src="/js/storefront-configuration.js" defer></script>
@endif
@endsection
