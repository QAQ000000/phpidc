@extends('sales.layout')
@section('title', '支付账单')
@section('content')
<x-console.page-header :title="'支付账单 '.$invoice->invoice_no" description="前往支付渠道完成付款，账单状态以实际到账结果为准。">
    <x-slot:actions><a class="console-button is-secondary" href="/console/invoices/{{ $invoice->id }}">返回账单查看付款结果</a></x-slot:actions>
</x-console.page-header>
<div class="console-grid two">
    <x-console.card title="付款信息">
        <dl class="console-kv">
            <div><dt>账单号</dt><dd>{{ $invoice->invoice_no }}</dd></div>
            <div><dt>账单总额</dt><dd class="console-money">{{ $invoice->total_amount }} {{ $invoice->currency }}</dd></div>
            <div><dt>钱包分配</dt><dd class="console-money">{{ $invoice->credit_applied }} {{ $invoice->currency }}</dd></div>
            <div><dt>本次外部支付</dt><dd class="console-money">{{ $invoice->gateway_amount }} {{ $invoice->currency }}</dd></div>
        </dl>
    </x-console.card>
    <x-console.card title="完成支付">
        @if($result->success)
            <p><x-console.badge tone="info">支付已发起</x-console.badge></p>
            <p class="console-muted">支付完成后，请返回账单核对付款结果。</p>
            <div class="console-actions">
                @if($result->paymentUrl)<a class="console-button" href="{{ $result->paymentUrl }}" rel="noreferrer">前往支付</a>@endif
                @if($result->qrCodeUrl)<a class="console-button is-secondary" href="{{ $result->qrCodeUrl }}" rel="noreferrer">打开二维码</a>@endif
            </div>
        @else
            <p role="alert">暂时无法发起支付，请稍后重试；您也可以等待账单过期自动解冻钱包。</p>
            <p class="console-muted">可返回账单查看当前状态。</p>
        @endif
    </x-console.card>
</div>
@endsection
