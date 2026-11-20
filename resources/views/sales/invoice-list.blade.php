<div class="table-scroll" role="region" aria-label="账单记录" tabindex="0"><table class="console-table" aria-label="账单记录"><thead><tr><th scope="col">账单 / 商品</th><th scope="col">类型 / 状态</th><th scope="col" class="console-money">金额</th><th scope="col">创建时间</th><th scope="col">操作</th></tr></thead><tbody>
@forelse($invoices as $row)
    <tr>
        <td><a href="/console/invoices/{{ $row->id }}">{{ $row->invoice_no }}</a><div class="console-muted">{{ $row->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值' : ($row->product?->name ?? '历史商品') }}</div></td>
        <td>{{ \App\Support\CustomerLabels::INVOICE_TYPES[$row->type->value] }}<br><x-console.badge :tone="match($row->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ $row->type === \App\Enums\InvoiceType::WALLET_TOPUP && $row->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : \App\Support\CustomerLabels::INVOICE_STATUSES[$row->status->value] }}</x-console.badge></td>
        <td class="console-money">{{ $row->total_amount }} <small>{{ $row->currency }}</small></td>
        <td>{{ $row->created_at?->format('Y-m-d H:i') }}</td>
        <td><a href="/console/invoices/{{ $row->id }}">查看账单</a></td>
    </tr>
@empty<tr><td colspan="5" class="console-empty">暂无符合条件的账单。</td></tr>@endforelse
</tbody></table></div>
