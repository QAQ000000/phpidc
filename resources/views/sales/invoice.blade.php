@extends('sales.layout')
@section('content')
<h1>账单 {{ $invoice->invoice_no }}</h1><p>状态：{{ $invoice->status->value }}</p><p>总额 {{ $invoice->total_amount }} {{ $invoice->currency }}；钱包 {{ $invoice->credit_applied }}；渠道待付 {{ $invoice->gateway_amount }}</p>
@if($invoice->status === \App\Enums\InvoiceStatus::UNPAID)<form method="post" action="/console/invoices/{{ $invoice->id }}/pay">@csrf<button>继续支付</button></form>@endif
@if($invoice->instance_id)<p><a href="/console/instances/{{ $invoice->instance_id }}">查看交付 / 领取卡密</a></p>@endif
@endsection
