@extends('sales.layout')
@section('content')
<h1>支付账单 {{ $invoice->invoice_no }}</h1>
@if($result->success)
@if($result->paymentUrl)<p><a href="{{ $result->paymentUrl }}" rel="noreferrer">前往支付</a></p>@endif
@if($result->qrCodeUrl)<p>扫码地址：<a href="{{ $result->qrCodeUrl }}" rel="noreferrer">打开二维码</a></p>@endif
@else<p role="alert">暂时无法发起支付，请稍后重试；您也可以等待账单过期自动解冻钱包。</p>@endif
<p><a href="/console/invoices/{{ $invoice->id }}">返回账单查看付款结果</a></p>
@endsection
