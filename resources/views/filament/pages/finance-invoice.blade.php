<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><h2>{{ $invoice->invoice_no }} · {{ $invoice->status->value }}</h2>
<p>客户 #{{ $invoice->user_id }} · 商品 #{{ $invoice->product_id ?? '—' }} · {{ $invoice->type->value }} · 币种 {{ $invoice->currency }}</p>
<p>账单总额 {{ $invoice->total_amount }} = 钱包 {{ $invoice->credit_applied }} + 网关 {{ $invoice->gateway_amount }}</p>
<p>优惠 {{ $invoice->discount_amount }}（已计入总额，不再减一次） · 预计上游成本 {{ $invoice->upstream_expected_cost ?? '—' }}</p>
<p>服务：@if($instance)#{{ $instance->id }} {{ $instance->display_name }} · {{ $instance->status->value }}@else 无关联服务@endif</p>
<p class="inventory-note">支付时间 {{ $invoice->paid_at ?? '—' }} · 账单到期 {{ $invoice->expires_at ?? '—' }}</p>
<a class="inventory-link" href="/admin/finance">返回账单</a> · <a class="inventory-link" href="/admin/finance/ledger?invoice_id={{ $invoice->id }}">查看关联流水和回调</a>
@can('tasks.view') · <a class="inventory-link" href="/admin/tasks?invoice_id={{ $invoice->id }}">关联任务</a>@endcan
</section>
@can('refund-invoice', $invoice)
@if($invoice->status === \App\Enums\InvoiceStatus::PAID)
<section class="inventory-box"><h2>全额退款 {{ $invoice->total_amount }} {{ $invoice->currency }}</h2>
<p class="inventory-note">先确认服务已终止。若明确豁免，必须单独勾选保留服务；已交付卡密不会回收，退款会记录损失。佣金不足扣回的差额登记应收，仍需人工处理。</p>
<form method="post" action="/admin/finance/invoices/{{ $invoice->id }}/refund" class="inventory-form">@csrf
<label>处理方式<select name="mode" required><option value="">请选择</option><option value="refund_wallet">退回客户钱包</option><option value="external_record">登记已完成的外部退款</option></select></label>
<label>退款原因<input name="reason" required maxlength="255" placeholder="填写经核实的原因与外部退款凭证编号"></label>
<label><span><input type="checkbox" name="keep_service" value="1"> 明确豁免终止前置条件，接受保留服务的损失</span></label>
<label><span><input type="checkbox" name="external_confirm" value="1"> 外部退款已经完成（仅外部登记时必选；本系统不打款）</span></label>
<label><span><input type="checkbox" name="confirm" value="1" required> 已核对客户、全额金额、服务状态及退款方式</span></label>
<button class="inventory-button">确认全额退款</button>
</form></section>
@else<p class="inventory-box">当前状态不可发起新退款。已退款账单可在流水中核对唯一退款记录。</p>@endif
@endcan
<section class="inventory-box inventory-scroll"><h2>支付尝试</h2><table class="inventory-table"><thead><tr><th>ID / 网关</th><th>精确订单号</th><th>金额 / 状态</th></tr></thead><tbody>
@forelse($attempts as $attempt)<tr><td>#{{ $attempt->id }} {{ $attempt->gateway }}</td><td>{{ $attempt->out_trade_no }}</td><td>{{ $attempt->amount }} · {{ $attempt->status }}</td></tr>@empty<tr><td colspan="3">没有支付尝试。</td></tr>@endforelse
</tbody></table>{{ $attempts->links() }}</section>
<section class="inventory-box inventory-scroll"><h2>支付回调核查</h2><table class="inventory-table"><thead><tr><th>ID / 网关交易</th><th>金额</th><th>处理结果 / 时间</th></tr></thead><tbody>
@forelse($callbacks as $callback)<tr><td>#{{ $callback->id }} {{ $callback->gateway }}<br>{{ $callback->gateway_trade_no }}</td><td>{{ $callback->amount }}</td><td>{{ $callback->outcome->value }}<br>{{ $callback->processed_at }}</td></tr>@empty<tr><td colspan="3">没有支付回调。</td></tr>@endforelse
</tbody></table>{{ $callbacks->links() }}</section>
@if($recoveryInfo)
<section class="fi-section"><h2>续费恢复核对（只读）</h2>
@foreach($recoveryInfo['invoices'] as $member)<p><a href="/admin/finance/invoice?invoice_id={{ $member->id }}">{{ $member->invoice_no }}</a> · {{ $member->status->value }}</p>@endforeach
<p>当前单：{{ $recoveryInfo['current']->invoice_no }}。{{ $recoveryInfo['eligible'] ? '可由客户本人重新核价确认；运营不可代扣钱包。' : $recoveryInfo['reason'] }}</p>
</section>
@endif
</x-filament-panels::page>
