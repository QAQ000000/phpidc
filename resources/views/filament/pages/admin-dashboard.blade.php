<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-dashboard.css?v=20261120-b1">
<div class="admin-ui admin-dashboard">
    <header class="admin-ui-heading">
        <p class="admin-dashboard-caption">查看当前业务状态，进入待办继续处理。</p>
        <p class="admin-dashboard-caption">更新于 {{ $updatedAt->format('Y-m-d H:i:s') }} · {{ config('app.timezone') }}</p>
    </header>
    @if($finance !== null)
        <section aria-label="财务概览" class="admin-ui">
            <div class="admin-dashboard-heading"><h2>财务概览</h2><a class="admin-ui-link" href="/admin/finance">查看全部账单 →</a></div>
            <div class="admin-dashboard-stats admin-dashboard-finance-stats">
                <article class="admin-dashboard-stat"><h3>今日商品结算额（退款前）</h3><div><strong data-dashboard-metric="today_paid">{{ $finance['today'] }}</strong><small>CNY</small></div></article>
                <article class="admin-dashboard-stat"><h3>本月商品结算额（退款前）</h3><div><strong data-dashboard-metric="month_paid">{{ $finance['month'] }}</strong><small>CNY</small></div></article>
                <article class="admin-dashboard-stat"><h3>待付款账单</h3><strong data-dashboard-metric="unpaid">{{ $finance['unpaid'] }}</strong><a class="admin-ui-link" href="/admin/finance?status=Unpaid">查看待付款 →</a></article>
                <article class="admin-dashboard-stat"><h3>未结支付异常</h3><strong data-dashboard-metric="payment_attention" @if($finance['payment_attention'] > 0) data-dashboard-alert="danger" @endif>{{ $finance['payment_attention'] }}</strong><a class="admin-ui-link" href="/admin/reconciliations">前往业务核对 →</a></article>
                <article class="admin-dashboard-stat"><h3>外部佣金提现待审</h3><strong data-dashboard-metric="pending_withdrawals">{{ $finance['external_pending'] }}</strong><a class="admin-ui-link" href="/admin/external-payouts?status=pending">查看外部提现 →</a></article>
                <article class="admin-dashboard-stat"><h3>佣金转钱包待审</h3><strong data-dashboard-metric="wallet_withdrawals">{{ $finance['wallet_pending'] }}</strong><a class="admin-ui-link" href="/admin/payouts?status=pending">查看转钱包申请 →</a></article>
            </div>
            <p class="admin-dashboard-caption">结算额按账单付款时间统计新购、续费及升级的 CNY 总额，包含钱包支付，不含钱包充值；退款保留原结算额，因此此数值不代表净收入。</p>
        </section>
    @endif
    @if($services !== null || $tasks !== null || $support !== null)
        <section aria-label="服务与待办" class="admin-ui">
            <div class="admin-dashboard-heading"><h2>服务与待办</h2></div>
            <div class="admin-dashboard-stats">
                @if($services !== null)
                    <article class="admin-dashboard-stat"><h3>运行中经典服务</h3><strong data-dashboard-metric="active_instances">{{ $services['active'] }}</strong><a class="admin-ui-link" href="/admin/upstream-instances?status=Active">查看运行中服务 →</a></article>
                    <article class="admin-dashboard-stat"><h3>7 天内到期经典服务</h3><strong data-dashboard-metric="due_instances">{{ $services['due'] }}</strong><a class="admin-ui-link" href="/admin/upstream-instances">前往服务列表 →</a></article>
                @endif
                @if($tasks !== null)
                    <article class="admin-dashboard-stat"><h3>失败履约任务</h3><strong data-dashboard-metric="failed_tasks" @if($tasks['failed'] > 0) data-dashboard-alert="danger" @endif>{{ $tasks['failed'] }}</strong><a class="admin-ui-link" href="/admin/tasks?status=failed">查看失败任务 →</a></article>
                @endif
                @if($support !== null)
                    <article class="admin-dashboard-stat"><h3>待回复工单</h3><strong data-dashboard-metric="open_tickets" @if($support['open'] > 0) data-dashboard-alert="warning" @endif>{{ $support['open'] }}</strong><a class="admin-ui-link" href="/admin/support?status=open">查看待回复 →</a></article>
                @endif
            </div>
            @if($services !== null)<p class="admin-dashboard-caption">经典服务按本地同步状态统计。到期范围为当前时刻起 7 天内，包含运行中和暂停的经典服务。</p>@endif
        </section>
    @endif
    <div @class(['admin-dashboard-bottom', 'admin-dashboard-without-finance' => $finance === null])>
        @if($finance !== null)
            <x-admin.card title="最近账单" description="按账单创建顺序展示最近 8 条，金额及状态保留原始记录。">
                <x-admin.table label="最近账单" class="admin-dashboard-invoices">
                    <x-slot:head><tr><th scope="col">账单 / 客户</th><th scope="col">类型 / 状态</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">创建时间</th></tr></x-slot:head>
                    @forelse($finance['invoices'] as $invoice)
                        <tr>
                            <td class="admin-ui-product-name"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $invoice->id }}">{{ $invoice->invoice_no }}</a><span class="admin-ui-meta">客户 #{{ $invoice->user_id }}</span></td>
                            <td><span class="admin-ui-meta">{{ match($invoice->type) { \App\Enums\InvoiceType::NEW_ORDER => '新购', \App\Enums\InvoiceType::RENEW => '续费', \App\Enums\InvoiceType::UPGRADE => '升级', \App\Enums\InvoiceType::DOWNGRADE => '降级', \App\Enums\InvoiceType::WALLET_TOPUP => '钱包充值' } }}</span><x-admin.badge :tone="match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ match($invoice->status) { \App\Enums\InvoiceStatus::PAID => '已入账', \App\Enums\InvoiceStatus::UNPAID => '待付款', \App\Enums\InvoiceStatus::REFUNDED => '已退款', \App\Enums\InvoiceStatus::CANCELLED => '已取消', \App\Enums\InvoiceStatus::EXPIRED => '已过期' } }}</x-admin.badge></td>
                            <td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="admin-ui-nowrap">{{ $invoice->created_at?->format('m-d H:i') ?? '—' }}</td>
                        </tr>
                    @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无账单</strong><p>新建账单后会在此显示。</p></td></tr>@endforelse
                </x-admin.table>
            </x-admin.card>
        @endif
        <div class="admin-dashboard-side">
            <x-admin.card title="快捷入口">
                <div class="admin-ui-card-body"><ul class="admin-dashboard-links">
                    @if($finance !== null)<li><a class="admin-ui-link" href="/admin/finance">财务账单</a></li>@endif
                    @if($tasks !== null)<li><a class="admin-ui-link" href="/admin/tasks?status=failed">失败履约任务</a><x-admin.badge :tone="$tasks['failed'] > 0 ? 'danger' : 'neutral'">{{ $tasks['failed'] }}</x-admin.badge></li>@endif
                    @if($support !== null)<li><a class="admin-ui-link" href="/admin/support?status=open">待回复工单</a><x-admin.badge :tone="$support['open'] > 0 ? 'warning' : 'neutral'">{{ $support['open'] }}</x-admin.badge></li>@endif
                    @can('upstream.view')<li><a class="admin-ui-link" href="/admin/upstreams">{{ auth()->user()->can('upstream.import') ? '商品导入与连接' : '查看商品与连接' }}</a></li>@endcan
                    @can('settings.view')<li><a class="admin-ui-link" href="/admin/settings?tab=site">系统配置</a></li>@endcan
                </ul>
                @if($finance === null && $services === null && $tasks === null && $support === null)<p class="admin-dashboard-caption">可从左侧菜单进入已授权的功能。经营数据按查看权限展示。</p>@endif
                </div>
            </x-admin.card>
            @if($support !== null)
                <x-admin.card title="待回复工单">
                    <div class="admin-ui-card-body" role="region" aria-label="待回复工单"><ul class="admin-dashboard-links">
                        @forelse($support['tickets'] as $ticket)<li><div class="admin-dashboard-ticket"><a class="admin-ui-link" href="/admin/support?edit={{ $ticket->id }}">{{ $ticket->subject }}</a><span class="admin-ui-meta">工单 #{{ $ticket->id }} · 客户 #{{ $ticket->user_id }} · {{ $ticket->created_at?->format('m-d H:i') ?? '—' }}</span></div></li>
                        @empty<li class="admin-dashboard-caption">暂时没有待回复的工单。</li>@endforelse
                    </ul></div>
                </x-admin.card>
            @endif
        </div>
    </div>
</div>
</x-filament-panels::page>
