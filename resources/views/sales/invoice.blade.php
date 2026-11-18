@extends('sales.layout')
@section('title', '账单详情')
@section('content')
<p><a href="/console/invoices">返回我的账单</a></p><h1>账单 {{ $invoice->invoice_no }}</h1>
@if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)
<p>钱包充值 · <a href="/console/wallet">查看钱包</a></p>
@else<p>商品：{{ $invoice->product?->name ?? '历史商品' }} · {{ \App\Support\CustomerLabels::INVOICE_TYPES[$invoice->type->value] }}</p>@endif
<p>状态：{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : \App\Support\CustomerLabels::INVOICE_STATUSES[$invoice->status->value] }}（{{ $invoice->status->value }}）</p>
@if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)
<p>充值金额 {{ $invoice->total_amount }} {{ $invoice->currency }} · 通过外部网关支付，到账后增加可用余额。暂不支持充值退款。</p>
@else<p>总额 {{ $invoice->total_amount }} {{ $invoice->currency }}；优惠 {{ $invoice->discount_amount }}；钱包 {{ $invoice->credit_applied }}；外部支付金额 {{ $invoice->gateway_amount }}</p>@endif
<p>创建：{{ $invoice->created_at }}<br>付款：{{ $invoice->paid_at ?? '未付款' }}<br>支付截止：{{ $invoice->expires_at ?? '—' }}</p>
@if(count($items))<h2>账单明细</h2><div class="table-scroll"><table><thead><tr><th>项目</th><th>数量</th><th>单价</th><th>金额</th></tr></thead><tbody>@foreach($items as $item)<tr><td>{{ $item['label'] ?? '账单项目' }}</td><td>{{ $item['qty'] ?? '' }}</td><td>{{ $item['unit_price'] ?? '' }}</td><td>{{ $item['amount'] ?? '' }} {{ $invoice->currency }}</td></tr>@endforeach</tbody></table></div>@endif
@if($invoice->status === \App\Enums\InvoiceStatus::UNPAID)
@if(($invoice->line_items['automatic_renewal']['selection_pending'] ?? false) && $linkedInstance)
<section><h2>确认续费付款方式</h2><p>此账单为到期提醒自动生成，请先确认本次付款方式。价格和优惠已固定。</p>
<form method="post" action="/console/instances/{{ $linkedInstance->id }}/operations">@csrf
<input type="hidden" name="action" value="renew"><input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::ulid()) }}">
<input type="hidden" name="reason" value="确认自动续费账单付款方式"><input type="hidden" name="confirm" value="1">
<label><input type="checkbox" name="use_credit" value="1" @checked(old('use_credit'))> 使用可用钱包余额</label>
<label>剩余金额支付方式 <select name="gateway"><option value="epay_wechat" @selected(old('gateway') === 'epay_wechat')>微信</option><option value="epay_alipay" @selected(old('gateway') === 'epay_alipay')>支付宝</option></select></label>
<button>确认付款方式</button></form></section>
@else
@if($invoice->expires_at === null || $invoice->expires_at->isFuture())<form method="post" action="/console/invoices/{{ $invoice->id }}/pay">@csrf<button>继续支付</button></form>@else<p>支付有效期已过，请等待状态更新或联系管理员。</p>@endif
@endif
@endif
@if($linkedInstance)<section><h2>关联服务</h2><p>{{ $linkedInstance->display_name }} · {{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$linkedInstance->status->value] }}</p><a href="/console/instances/{{ $linkedInstance->id }}">查看交付 / 领取卡密</a></section>
@elseif($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID)<p>充值已到账，可在钱包中核对余额。</p>
@elseif($invoice->status === \App\Enums\InvoiceStatus::PAID)<p>已付款，交付信息等待确认；如长时间未更新，请联系管理员。</p>@endif
@include('sales.renewal-recovery-status')
@endsection
