@extends('sales.layout')
@section('title', '我的钱包')
@section('content')
<h1>我的钱包</h1>
<p>可用余额 {{ $user->credit }} CNY · 冻结余额 {{ $user->frozen_credit }} CNY</p>
<p>结清冻结 {{ $user->wallet_clearance_frozen }} CNY · <a href="/console/wallet/clearances">申请钱包结清</a></p>
<section><h2>钱包充值</h2>
<p>每笔 1.00–10000.00 元，仅支持人民币及外部支付。支付成功后增加可用余额；充值不参加优惠或返佣，暂不支持充值退款。</p>
@if($enabled)
<form method="post" action="/console/wallet/topups" class="account-form">@csrf
<input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
<label>充值金额（元）<input name="amount" type="text" inputmode="decimal" required maxlength="13" value="{{ old('amount') }}" placeholder="例如 100.00"></label>
<label>支付方式<select name="gateway"><option value="epay_wechat" @selected(old('gateway') === 'epay_wechat')>微信</option><option value="epay_alipay" @selected(old('gateway') === 'epay_alipay')>支付宝</option></select></label>
<button>创建充值账单</button></form>
@else<p>充值暂未开放。</p>@endif
</section>
<h2>充值记录</h2><p>共 {{ $invoices->total() }} 条</p>
@include('sales.invoice-list')
{{ $invoices->links('sales.pagination') }}
@endsection
