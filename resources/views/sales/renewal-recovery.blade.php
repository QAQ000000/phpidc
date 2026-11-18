@extends('sales.layout')
@section('content')
<h1>确认恢复续费</h1>
<p>原账单 {{ $invoice->invoice_no }} 保留过期状态。本次将创建新的续费账单，请核对金额。</p>
<p>原账单金额 {{ $invoice->total_amount }} {{ $invoice->currency }}。</p>
<p>总额 {{ $quote['total'] }} {{ $quote['currency'] }}；优惠 {{ $quote['discount'] }}；钱包 {{ $quote['credit'] }}；外部支付 {{ $quote['gateway_amount'] }}。</p>
<p>报价有效至 {{ \Illuminate\Support\Carbon::createFromTimestamp($quote['expires'])->timezone(config('app.timezone')) }}。确认时将再次核价；新账单须在确认创建后 {{ $quote['payment_window_minutes'] }} 分钟内付款，具体截止时间显示于新账单。</p>
<form method="post" action="/console/invoices/{{ $invoice->id }}/renewal-recovery">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}">
<input type="hidden" name="quote_token" value="{{ $quote['quote_token'] }}">
<input type="hidden" name="use_credit" value="{{ $quote['use_credit'] ? '1' : '0' }}">
<input type="hidden" name="gateway" value="{{ $quote['gateway'] }}">
<input type="hidden" name="coupon_code" value="{{ $quote['coupon_code'] }}">
<label>恢复原因<input name="reason" required maxlength="1000" value="{{ old('reason') }}"></label>
<label><input type="checkbox" name="confirm" value="1" required>确认本次续费金额与钱包支付授权（余额足够时立即结算）</label>
<button>确认并创建续费账单</button></form>
<p><a href="/console/invoices/{{ $invoice->id }}">返回原账单重新核价</a></p>
@endsection
