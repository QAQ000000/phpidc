@extends('sales.layout')
@section('title', '我的钱包')
@section('content')
<x-console.page-header title="我的钱包" description="管理可用余额、充值与历史到账记录。">
    <x-slot:actions><a class="console-button is-secondary" href="/console/wallet/clearances">申请钱包结清</a><a class="console-button is-secondary" href="/console/invoices">我的账单</a></x-slot:actions>
</x-console.page-header>
<div class="console-stats">
    <div class="console-stat"><span>可用余额</span><strong>{{ $user->credit }} <small>CNY</small></strong><small>可用于支付服务账单</small></div>
    <div class="console-stat"><span>冻结余额</span><strong>{{ $user->frozen_credit }} <small>CNY</small></strong><small>已分配至待付款账单</small></div>
    <div class="console-stat"><span>结清冻结</span><strong>{{ $user->wallet_clearance_frozen }} <small>CNY</small></strong><small>钱包结清申请占用的金额</small></div>
</div>
<x-console.card title="钱包充值" description="每笔 1.00–10000.00 元，仅支持人民币及外部支付。">
    <p class="console-muted">支付成功后增加可用余额；充值不参加优惠或返佣，暂不支持充值退款。</p>
    @if($enabled && app(\App\Support\PaymentChannels::class)->available() !== [])
        <form method="post" action="/console/wallet/topups" class="account-form">@csrf
            <input type="hidden" name="request_id" value="{{ is_string(old('request_id')) ? old('request_id') : $requestId }}">
            <label for="topup-amount">充值金额（元）<input id="topup-amount" name="amount" type="text" inputmode="decimal" required maxlength="13" value="{{ is_string(old('amount')) ? old('amount') : '' }}" placeholder="例如 100.00"></label>
            <label for="topup-gateway">支付方式<select id="topup-gateway" name="gateway">@include('sales.payment-options', ['selectedGateway' => old('gateway')])</select></label>
            <button class="console-button">创建充值账单</button>
        </form>
    @else<div class="console-empty">{{ $enabled ? '外部支付渠道暂未开放，无法创建新的充值账单。' : '充值暂未开放。' }}</div>@endif
</x-console.card>
<x-console.card title="充值记录" :description="'共 '.$invoices->total().' 条'">
    @include('sales.invoice-list')
    {{ $invoices->links('sales.pagination') }}
</x-console.card>
@endsection
