@extends('layouts.public')
@section('content')
<div class="breadcrumb"><a href="/store">产品商城</a> / <a href="/store/{{ $product->category->slug }}">{{ $product->category->name }}</a> / {{ $product->name }}</div>
<div class="detail"><article><div class="eyebrow">{{ $product->category->name }}</div><h1>{{ $product->name }}</h1><p class="lead">{{ $live ? '云服务 · 按所选周期计费' : '数字卡密 · 一次购买，一次交付' }}</p>
<div class="prose description">{{ $product->description }}</div><p><a href="{{ $product->publicPath() }}.md">以 Markdown 阅读</a></p><p class="muted">需要了解使用方法？访问<a href="/help">帮助中心</a>。</p></article>
<aside class="panel" aria-label="价格与购买"><h2>{{ $live ? '计费与参考价格' : '价格与库存' }}</h2>
@foreach($cycles as $cycle)<div class="price-row"><span>{{ $cycle['label'] }}</span><div class="price">{{ $cycle['price'] ?? '待报价' }} {{ $product->currency }}</div>@if($cycle['setup_fee'] !== null)<small>开通费 {{ $cycle['setup_fee'] }} {{ $product->currency }}</small>@endif</div>@endforeach
@if($live)<p class="muted">以上为最近导入的参考价格，创建账单时获取实时价格和库存，请核对账单后再付款。</p>@elseif($stock === null)<p>付款后自动生成兑换码</p><p class="muted">兑换面额见商品说明，领取后请登录对应服务站点兑换。异常交付请联系管理员核对。</p>@else<p>当前可用卡密：{{ $stock }} 张</p><p class="muted">卡密在付款后分配，库存可能发生变化。交付后仅本人可在客户中心查看。</p>@endif
@if($product->require_kyc)<p>购买此产品需要完成实名认证。</p>@endif
@if($product->require_phone)<p>购买此产品需要绑定手机号。</p>@endif
@if($cycles === [])<p class="empty">暂无可购买的计费周期。</p>
@elseif(!$live && $stock === 0)<p class="empty">暂时售罄，请稍后再来。</p>
@else
@auth
@if($live)<p><a class="button" href="/console/products/{{ $product->id }}/configure">选择完整配置与实时报价</a></p>@else
<form method="post" action="/checkout">@csrf<label>优惠券码（先在客户中心领取；仅新购/续费）<input name="coupon_code" maxlength="64"></label><input type="hidden" name="product_id" value="{{ $product->id }}">
@include('sales.purchase-request')
<label for="billing-cycle">计费周期</label><select id="billing-cycle" name="billing_cycle">@foreach($cycles as $cycle)<option value="{{ $cycle['value'] }}">{{ $cycle['label'] }}</option>@endforeach</select>
@if($live || ($product->provisioning_rules['hostname_mode'] ?? '') === 'custom')<label for="hostname">主机名</label><input id="hostname" name="hostname" @required(($product->provisioning_rules['hostname_mode'] ?? '') === 'custom') value="{{ old('hostname') }}">@endif
@if($live || isset($product->provisioning_rules['password_min_len']) || isset($product->provisioning_rules['password_max_len']) || isset($product->provisioning_rules['password_regex']))<label for="password">开通密码</label><input id="password" name="password" type="password" required autocomplete="new-password">@endif
<label><input type="checkbox" name="use_credit" value="1"> 使用钱包余额</label><label for="gateway">其余金额的支付方式</label><select id="gateway" name="gateway"><option value="epay_wechat">微信支付</option><option value="epay_alipay">支付宝</option></select>
<p class="muted">勾选钱包后，余额足够会立即结算；否则先冻结余额，再支付剩余金额。</p><button>{{ $live ? '实时核价并创建账单' : '创建订单' }}</button></form>@endif
@else<p>登录后即可创建订单，并在客户中心领取服务。</p><a class="button" href="/login">登录 / 注册后购买</a>@endauth
@endif
</aside></div>
@endsection
