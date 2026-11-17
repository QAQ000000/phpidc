<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>按已存账单核查金额。退款需要独立财务权限、原因与明确确认。</p>
<form method="get" class="inventory-form">
<label>完整账单号<input name="invoice_no" value="{{ request('invoice_no') }}" maxlength="64"></label>
<label>客户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label>
<label>状态<select name="status"><option value="">全部</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->value }}</option>@endforeach</select></label>
<button class="inventory-button">筛选</button><a class="inventory-link" href="/admin/finance">重置</a><a class="inventory-link" href="/admin/finance/ledger">流水与支付回调</a>
</form></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>账单 / 客户</th><th>类型 / 状态</th><th>总额</th><th>钱包 / 网关</th><th>核查</th></tr></thead><tbody>
@forelse($invoices as $invoice)<tr><td>{{ $invoice->invoice_no }}<br><span class="inventory-meta">账单 #{{ $invoice->id }} · 客户 #{{ $invoice->user_id }}</span></td><td>{{ $invoice->type->value }}<br>{{ $invoice->status->value }}</td><td>{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td>{{ $invoice->credit_applied }} / {{ $invoice->gateway_amount }}</td><td><a class="inventory-link" href="/admin/finance/invoice?invoice_id={{ $invoice->id }}">核查与退款</a></td></tr>
@empty<tr><td colspan="5">没有符合条件的账单。</td></tr>@endforelse
</tbody></table>{{ $invoices->links() }}</div>
</x-filament-panels::page>
