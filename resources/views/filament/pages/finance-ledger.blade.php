<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="{{ asset('css/admin-operations.css') }}">
<div class="admin-ui admin-operations-page" x-data>
@include('filament.inventory-feedback')
<header class="admin-ui-heading"><div><p>流水只读，方向由类型决定。退款佣金应收显示本金及有效清偿后的剩余金额；<a class="admin-ui-link" href="/admin/finance/receivables">登记清偿与冲正</a>。</p></div></header>
<x-admin.card title="财务流水">
    <x-admin.filters action="/admin/finance/ledger">
        <label>客户 ID<input name="user_id" type="number" min="1" value="{{ $filters['user_id'] }}"></label>
        <label>账单 ID<input name="invoice_id" type="number" min="1" value="{{ $filters['invoice_id'] }}"></label>
        <label>流水类型<select name="type"><option value="">全部</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ match($type->value) { 'wallet_adjust_credit' => '钱包加款', 'wallet_adjust_debit' => '钱包扣款', default => $type->value } }}</option>@endforeach</select></label>
        <label>回调结果<select name="outcome"><option value="">全部</option>@foreach($outcomes as $outcome)<option value="{{ $outcome->value }}" @selected($filters['outcome'] === $outcome->value)>{{ $outcome->value }}</option>@endforeach</select></label>
    </x-admin.filters>
    <x-admin.table label="财务流水"><x-slot:head><tr><th scope="col">流水 / 客户</th><th scope="col" class="admin-ui-number">类型 / 金额</th><th scope="col" class="admin-ui-number">钱包 / 冻结余额</th><th scope="col">幂等引用 / 佣金应收</th></tr></x-slot:head>
        @forelse($ledger as $entry)<tr>
            <td>#{{ $entry->id }} · 客户 #{{ $entry->user_id }}@if($entry->invoice_id)<span class="admin-ui-meta"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $entry->invoice_id }}">账单 #{{ $entry->invoice_id }}</a></span>@endif<span class="admin-ui-meta">{{ $entry->created_at }}</span></td>
            <td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} {{ $entry->meta['currency'] ?? '' }}<span class="admin-ui-meta">{{ match($entry->type->value) { 'wallet_adjust_credit' => '钱包加款', 'wallet_adjust_debit' => '钱包扣款', default => $entry->type->value } }}</span></td>
            <td class="admin-ui-number admin-ui-nowrap">{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td>
            <td>{{ $entry->reference_key }}@if($entry->type === \App\Enums\TransactionType::AFFILIATE_CLAWBACK)@php($projection = $receivables->get($entry->id))<p class="admin-operations-note">扣回 {{ $entry->meta['recovered_amount'] ?? '—' }} · 应收 {{ $projection['principal'] }}（原始） · 剩余 {{ $projection['valid'] ? $projection['remaining'] : '证据异常，须核对' }}</p>@endif</td>
        </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有匹配的流水。</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$ledger" />
</x-admin.card>
<x-admin.card :title="$filters['user_id'] !== '' ? '客户账单的支付回调' : '支付回调（包含未知账单）'" description="客户 ID 同时筛选流水及归属于该客户账单的回调；筛选客户时不含无法确认归属的未知账单回调。回调不提供原文或重放按钮，异常需人工核查。">
    <x-admin.table label="支付回调"><x-slot:head><tr><th scope="col">回调 / 账单</th><th scope="col">网关 / 交易号</th><th scope="col" class="admin-ui-number">金额 / 结果</th><th scope="col">处理时间</th></tr></x-slot:head>
        @forelse($callbacks as $callback)<tr>
            <td>#{{ $callback->id }}<span class="admin-ui-meta">@if($callback->invoice_id)<a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $callback->invoice_id }}">账单 #{{ $callback->invoice_id }}</a>@else 未知账单：{{ $callback->invoice_no ?? '—' }}@endif</span></td>
            <td>{{ $callback->gateway }}<span class="admin-ui-meta">{{ $callback->gateway_trade_no }}</span></td>
            <td class="admin-ui-number admin-ui-nowrap">{{ $callback->amount }}<span class="admin-ui-meta"><x-admin.badge :tone="str_starts_with($callback->outcome->value, 'rejected_') || $callback->outcome->value === 'unknown_invoice' ? 'warning' : 'neutral'">{{ $callback->outcome->value }}</x-admin.badge></span></td><td>{{ $callback->processed_at }}</td>
        </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有匹配的回调。</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$callbacks" />
</x-admin.card>
</div>
</x-filament-panels::page>
