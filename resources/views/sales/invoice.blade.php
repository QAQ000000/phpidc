@extends('sales.layout')
@section('title', '账单详情')
@section('content')
<x-console.page-header :title="'账单 '.$invoice->invoice_no" :description="$invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值账单与到账信息' : '核对账单金额、付款进度及关联服务'">
    <x-slot:actions><a class="console-button is-secondary" href="/console/invoices">返回我的账单</a>@if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)<a class="console-button is-secondary" href="/console/wallet">查看钱包</a>@endif</x-slot:actions>
</x-console.page-header>
<div class="console-grid two">
    <div>
        <x-console.card title="账单信息">
            <dl class="console-kv">
                <div><dt>商品</dt><dd>{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值' : ($invoice->product?->name ?? '历史商品') }}</dd></div>
                <div><dt>账单类型</dt><dd>{{ \App\Support\CustomerLabels::INVOICE_TYPES[$invoice->type->value] }}</dd></div>
                <div><dt>状态</dt><dd><x-console.badge :tone="match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : \App\Support\CustomerLabels::INVOICE_STATUSES[$invoice->status->value] }}</x-console.badge> <span class="console-muted">（{{ $invoice->status->value }}）</span></dd></div>
                <div><dt>创建</dt><dd>{{ $invoice->created_at }}</dd></div>
                <div><dt>付款</dt><dd>{{ $invoice->paid_at ?? '未付款' }}</dd></div>
                <div><dt>支付截止</dt><dd>{{ $invoice->expires_at ?? '—' }}</dd></div>
            </dl>
        </x-console.card>
        @if(count($items))
            <x-console.card title="账单明细">
                <div class="table-scroll" role="region" aria-label="账单明细" tabindex="0"><table class="console-table" aria-label="账单明细"><thead><tr><th scope="col">项目</th><th scope="col">数量</th><th scope="col" class="console-money">单价</th><th scope="col" class="console-money">金额</th></tr></thead><tbody>
                    @foreach($items as $item)<tr><td>{{ is_scalar($item['label'] ?? null) ? $item['label'] : '账单项目' }}</td><td>{{ is_scalar($item['qty'] ?? null) ? $item['qty'] : '' }}</td><td class="console-money">{{ is_scalar($item['unit_price'] ?? null) ? $item['unit_price'] : '' }}</td><td class="console-money">{{ is_scalar($item['amount'] ?? null) ? $item['amount'] : '' }} {{ $invoice->currency }}</td></tr>@endforeach
                </tbody></table></div>
            </x-console.card>
        @endif
        @if($linkedInstance)
            <x-console.card title="关联服务">
                <p>{{ $linkedInstance->display_name }} · {{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$linkedInstance->status->value] }}</p>
                <div class="console-actions"><a class="console-button is-secondary" href="/console/instances/{{ $linkedInstance->id }}">查看交付 / 领取卡密</a></div>
            </x-console.card>
        @elseif($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID)
            <x-console.card title="充值到账"><p>充值已到账，可在钱包中核对余额。</p></x-console.card>
        @elseif($invoice->status === \App\Enums\InvoiceStatus::PAID)
            <x-console.card title="交付进度"><p>已付款，交付信息等待确认；如长时间未更新，请联系管理员。</p></x-console.card>
        @endif
    </div>
    <div class="console-payment-summary">
        <x-console.card title="付款摘要">
            <div class="console-stat"><span>{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '充值金额' : '账单总额' }}</span><strong>{{ $invoice->total_amount }} <small>{{ $invoice->currency }}</small></strong></div>
            @if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)
                <p class="console-muted">通过外部网关支付，到账后增加可用余额。暂不支持充值退款。</p>
            @else
                <dl class="console-kv">
                    <div><dt>优惠</dt><dd class="console-money">{{ $invoice->discount_amount }} {{ $invoice->currency }}</dd></div>
                    <div><dt>钱包</dt><dd class="console-money">{{ $invoice->credit_applied }} {{ $invoice->currency }}</dd></div>
                    <div><dt>外部支付金额</dt><dd class="console-money">{{ $invoice->gateway_amount }} {{ $invoice->currency }}</dd></div>
                </dl>
                <p class="console-muted">优惠已计入总额；钱包与外部支付按本账单确定的分配结算。</p>
            @endif
            @if($invoice->status === \App\Enums\InvoiceStatus::UNPAID)
                @if(($invoice->line_items['automatic_renewal']['selection_pending'] ?? false) && $linkedInstance)
                    <h3>确认续费付款方式</h3><p class="console-muted">此账单为到期提醒自动生成，请先确认本次付款方式。价格和优惠已固定。</p>
                    <form method="post" action="/console/instances/{{ $linkedInstance->id }}/operations">@csrf
                        <input type="hidden" name="action" value="renew"><input type="hidden" name="request_id" value="{{ is_string(old('request_id')) ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}">
                        <input type="hidden" name="reason" value="确认自动续费账单付款方式"><input type="hidden" name="confirm" value="1">
                        <label><input type="checkbox" name="use_credit" value="1" @checked(old('use_credit'))> 使用可用钱包余额</label>
                        <label>剩余金额支付方式 <select name="gateway">@include('sales.payment-options', ['selectedGateway' => old('gateway')])</select></label>
                        <button class="console-button">确认付款方式</button>
                    </form>
                @else
                    @if($invoice->expires_at === null || $invoice->expires_at->isFuture())
                        @if(app(\App\Support\PaymentChannels::class)->enabled($invoice->gateway ?? ''))
                            <form method="post" action="/console/invoices/{{ $invoice->id }}/pay">@csrf<button class="console-button">继续支付</button></form>
                        @else<p class="console-muted">此账单的支付渠道已暂停，请联系管理员。</p>@endif
                    @else<p class="console-muted">支付有效期已过，请等待状态更新或联系管理员。</p>@endif
                @endif
            @endif
        </x-console.card>
    </div>
</div>
@include('sales.renewal-recovery-status')
@endsection
