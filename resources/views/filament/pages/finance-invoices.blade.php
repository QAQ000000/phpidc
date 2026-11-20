<x-filament-panels::page>
@include('filament.admin-ui-styles')
@php($workspaceOrigin = \App\Support\AdminWorkspaceNavigation::origin(auth()->user(), 'invoices', null, request()->query()))
<div class="admin-ui">
    @include('filament.inventory-feedback')
    <header class="admin-ui-heading">
        <div><h2>财务账单</h2><p>按已存账单核查金额、支付与退款状态。</p></div>
        <div class="admin-ui-actions"><a class="admin-ui-button" href="/admin/finance/ledger">流水与支付回调</a></div>
    </header>
    <x-admin.card title="账单列表" description="完整账单号精确匹配，金额按账单原始记录展示。">
        <x-admin.filters action="/admin/finance">
            <label class="admin-ui-search" for="invoice-number">完整账单号<input id="invoice-number" name="invoice_no" value="{{ $filters['invoice_no'] }}" maxlength="64" placeholder="输入完整账单号"></label>
            <label for="invoice-customer">客户 ID<input id="invoice-customer" name="user_id" type="number" min="1" value="{{ $filters['user_id'] }}" placeholder="全部客户"></label>
            <label for="invoice-status">状态<select id="invoice-status" name="status"><option value="">全部状态</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $statusLabels[$status->value] }}（{{ $status->value }}）</option>@endforeach</select></label>
            <label for="invoice-type">类型<select id="invoice-type" name="type"><option value="">全部类型</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected($filters['type'] === $type->value)>{{ $typeLabels[$type->value] }}</option>@endforeach</select></label>
        </x-admin.filters>
        <x-admin.table label="财务账单列表">
            <x-slot:head><tr><th scope="col">账单 / 客户</th><th scope="col">类型 / 状态</th><th scope="col" class="admin-ui-number">总额</th><th scope="col" class="admin-ui-number">钱包 / 网关</th><th scope="col">创建时间</th><th scope="col" class="admin-ui-row-actions">核查</th></tr></x-slot:head>
            @forelse($invoices as $invoice)
                @php($detailQuery = [...array_filter($filters, fn (string $value): bool => $value !== ''), 'invoice_id' => $invoice->id] + $workspaceOrigin)
                @if($invoices->currentPage() > 1)@php($detailQuery['page'] = $invoices->currentPage())@endif
                @php($detailUrl = '/admin/finance/invoice?'.http_build_query($detailQuery))
                <tr>
                    <td class="admin-ui-product-name"><a class="admin-ui-link" href="{{ $detailUrl }}">{{ $invoice->invoice_no }}</a><span class="admin-ui-meta">账单 #{{ $invoice->id }} · 客户 #{{ $invoice->user_id }}</span></td>
                    <td><span class="admin-ui-meta">{{ $typeLabels[$invoice->type->value] }} · {{ $invoice->type->value }}</span><x-admin.badge :tone="match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : $statusLabels[$invoice->status->value] }}</x-admin.badge><span class="admin-ui-meta">{{ $invoice->status->value }}</span></td>
                    <td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} <small>{{ $invoice->currency }}</small></td>
                    <td class="admin-ui-number admin-ui-nowrap">{{ $invoice->credit_applied }} / {{ $invoice->gateway_amount }}</td>
                    <td class="admin-ui-nowrap">{{ $invoice->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ $detailUrl }}">{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '核查到账' : '核查与退款' }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="admin-ui-empty"><strong>没有符合条件的账单。</strong><p>{{ array_filter($filters, fn (string $value): bool => $value !== '') !== [] ? '调整筛选条件或重置后重试。' : '新建账单后，可在此核查支付与资金分配。' }}</p>
                    @if(array_filter($filters, fn (string $value): bool => $value !== '') !== [])<a class="admin-ui-button" href="/admin/finance">重置筛选</a>@endif
                </td></tr>
            @endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$invoices" />
    </x-admin.card>
    @can('finance.wallet.configure')
        @php($walletInput = old('ui_form') === 'wallet-topup-settings')
        <x-admin.card title="钱包充值设置" description="仅控制后续新建充值账单；既有有效账单仍可支付。">
            <div class="admin-ui-card-body">
                <p>当前：<x-admin.badge :tone="app(\App\Actions\ConfigureWalletTopupAction::class)->enabled() ? 'success' : 'neutral'">{{ app(\App\Actions\ConfigureWalletTopupAction::class)->enabled() ? '已开启' : '已关闭' }}</x-admin.badge>。每笔固定 1.00–10000.00 CNY，关闭后不再创建新单，既有有效账单仍可支付。充值暂不支持退款。</p>
                <details @if($walletInput && $errors->any()) open @endif><summary>修改充值设置</summary>
                    <form method="post" action="/admin/finance/wallet-topup-settings" class="inventory-form">@csrf
                        <input type="hidden" name="ui_form" value="wallet-topup-settings">
                        <label>目标状态<select name="enabled" required><option value="">请选择</option><option value="1" @selected($walletInput && old('enabled') === '1')>开启</option><option value="0" @selected($walletInput && old('enabled') === '0')>关闭</option></select></label>
                        <label>原因<input name="reason" value="{{ $walletInput && is_string(old('reason')) ? old('reason') : '' }}" required maxlength="255"></label>
                        <label><span><input type="checkbox" name="confirm" value="1" required> 已确认充值范围及配置变更</span></label>
                        <button class="inventory-button">保存充值设置</button>
                    </form>
                </details>
            </div>
        </x-admin.card>
    @endcan
</div>
</x-filament-panels::page>
