<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>流水只读，方向由类型决定。应收是退款佣金差额记录，尚未收回；此页不提供自动扣款或余额调整。</p>
<form method="get" class="inventory-form"><label>客户 ID（流水）<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>账单 ID<input name="invoice_id" type="number" min="1" value="{{ request('invoice_id') }}"></label>
<label>流水类型<select name="type"><option value="">全部</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected(request('type') === $type->value)>{{ $type->value }}</option>@endforeach</select></label>
<label>回调结果<select name="outcome"><option value="">全部</option>@foreach($outcomes as $outcome)<option value="{{ $outcome->value }}" @selected(request('outcome') === $outcome->value)>{{ $outcome->value }}</option>@endforeach</select></label>
<button class="inventory-button">筛选</button><a class="inventory-link" href="/admin/finance/ledger">重置</a></form></section>
<section class="inventory-box inventory-scroll"><h2>财务流水</h2><table class="inventory-table"><thead><tr><th>流水 / 客户</th><th>类型 / 金额</th><th>钱包 / 冻结余额</th><th>幂等引用 / 佣金应收</th></tr></thead><tbody>
@forelse($ledger as $entry)<tr><td>#{{ $entry->id }} · 客户 #{{ $entry->user_id }}<br>@if($entry->invoice_id)<a class="inventory-link" href="/admin/finance/invoice?invoice_id={{ $entry->invoice_id }}">账单 #{{ $entry->invoice_id }}</a>@endif<br><span class="inventory-meta">{{ $entry->created_at }}</span></td><td>{{ $entry->type->value }}<br>{{ $entry->amount }} {{ $entry->meta['currency'] ?? '' }}</td><td>{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td><td>{{ $entry->reference_key }}
@if($entry->type === \App\Enums\TransactionType::AFFILIATE_CLAWBACK)<p>扣回 {{ $entry->meta['recovered_amount'] ?? '—' }} · 应收 {{ $entry->meta['receivable_amount'] ?? '—' }}</p>@endif
</td></tr>@empty<tr><td colspan="4">没有匹配的流水。</td></tr>@endforelse
</tbody></table>{{ $ledger->links() }}</section>
<section class="inventory-box inventory-scroll"><h2>支付回调（包含未知账单）</h2><p class="inventory-note">客户 ID 筛选仅适用于上方流水。回调不提供原文或重放按钮；金额不足、未知账单和渠道不匹配需人工核查。</p>
<table class="inventory-table"><thead><tr><th>回调 / 账单</th><th>网关 / 交易号</th><th>金额 / 结果</th><th>处理时间</th></tr></thead><tbody>
@forelse($callbacks as $callback)<tr><td>#{{ $callback->id }}<br>@if($callback->invoice_id)<a class="inventory-link" href="/admin/finance/invoice?invoice_id={{ $callback->invoice_id }}">账单 #{{ $callback->invoice_id }}</a>@else 未知账单：{{ $callback->invoice_no ?? '—' }}@endif</td><td>{{ $callback->gateway }}<br>{{ $callback->gateway_trade_no }}</td><td>{{ $callback->amount }}<br>{{ $callback->outcome->value }}</td><td>{{ $callback->processed_at }}</td></tr>@empty<tr><td colspan="4">没有匹配的回调。</td></tr>@endforelse
</tbody></table>{{ $callbacks->links() }}</section>
</x-filament-panels::page>
