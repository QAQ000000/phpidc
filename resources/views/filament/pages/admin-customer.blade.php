<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-customers.css?v=20261120-c1">
@php
    $closed = $customer->trashed() || $customer->anonymized_at !== null;
    $instanceLabels = ['Pending' => '待开通', 'Active' => '运行中', 'Suspended' => '已暂停', 'Cancelled' => '已取消', 'Fraud' => '异常', 'Deleted' => '已删除', 'ProvisionFailed' => '开通失败'];
    $invoiceLabels = ['Unpaid' => '待付款', 'Paid' => '已付款', 'Refunded' => '已退款', 'Cancelled' => '已取消', 'Expired' => '已过期'];
    $kycLabels = ['pending' => '待核对', 'verified' => '已认证', 'failed' => '未通过'];
    $ticketLabels = ['open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭'];
    $channelLabels = ['email' => '邮件', 'mail' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram'];
    $deliveryLabels = ['sent' => '已受理', 'failed' => '失败', 'simulated' => '模拟，未外发', 'pending' => '待发送', 'unknown' => '结果待核对', 'cancelled' => '已取消', 'skipped' => '已跳过', 'disabled' => '已停用'];
@endphp
<div class="admin-ui admin-customers-page">
    <header class="admin-ui-heading"><div><p>客户资料与本地业务记录只读查询，各区块独立分页。</p></div><div class="admin-ui-actions"><a class="admin-ui-button" href="/admin/customers{{ $closed ? '?status=closed' : '' }}">返回客户列表</a>@if($permissions['access'] && ! $closed)<a class="admin-ui-button" href="/admin/access?user_id={{ $customer->id }}">账户角色</a>@endif</div></header>
    <x-admin.card :title="'客户 #'.$customer->id">
        <div class="admin-ui-card-body">
            <div class="admin-customers-identity"><strong>{{ $customer->name }}</strong><x-admin.badge :tone="$closed ? 'neutral' : 'success'">{{ $closed ? '已注销' : '正常' }}</x-admin.badge><x-admin.badge :tone="$customer->kyc_verified_at ? 'success' : 'neutral'">{{ $customer->kyc_verified_at ? '实名已认证' : '实名未认证' }}</x-admin.badge></div>
            <dl class="admin-ui-facts admin-customers-profile" style="margin-top:20px"><div><dt>邮箱</dt><dd>{{ $customer->email }}<span class="admin-ui-meta">{{ $customer->email_verified_at ? '邮箱已验证' : '邮箱未验证' }}</span></dd></div><div><dt>手机号</dt><dd>{{ $customer->phone ?? '未绑定' }}</dd></div><div><dt>注册时间</dt><dd>{{ $customer->created_at?->format('Y-m-d H:i:s') }}</dd></div></dl>
        </div>
        @if($permissions['finance'] || $permissions['services'])<dl class="admin-customers-metrics">
            @if($permissions['finance'])<div class="admin-customers-metric"><dt>可用余额</dt><dd data-customer-metric="credit">{{ $customer->credit }}<small>CNY</small></dd></div><div class="admin-customers-metric"><dt>账单冻结</dt><dd data-customer-metric="frozen_credit">{{ $customer->frozen_credit }}<small>CNY</small></dd></div><div class="admin-customers-metric"><dt>结清冻结</dt><dd data-customer-metric="wallet_clearance_frozen">{{ $customer->wallet_clearance_frozen }}<small>CNY</small></dd></div>@endif
            @if($permissions['services'])<div class="admin-customers-metric"><dt>本地有效服务</dt><dd data-customer-metric="active_services">{{ $customer->active_services_count }}</dd></div>@endif
        </dl>@endif
        @if($permissions['finance'])<p class="admin-customers-notice">钱包三列保留各自含义；本页未执行应收核验，不据此判断客户是否欠款。</p>@endif
        @if($closed)<p class="admin-customers-notice">已注销账户仅保留匿名化资料及历史业务事实。</p>@endif
    </x-admin.card>

    @if($permissions['services'])
    <x-admin.card id="customer-services" title="服务记录" description="本地有效服务为运行中或已暂停记录；其他状态作为历史事实保留。">
        <x-slot:actions><a class="admin-ui-link" href="/admin/upstream-instances?user_id={{ $customer->id }}">查看经典服务列表</a></x-slot:actions>
        <x-admin.table label="客户服务记录"><x-slot:head><tr><th scope="col">服务 / 名称</th><th scope="col">驱动 / 连接</th><th scope="col">状态</th><th scope="col">主机 / IP</th><th scope="col">到期时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($services as $service)<tr data-customer-service="{{ $service->id }}"><td class="admin-customers-name">#{{ $service->id }} · {{ $service->display_name }}</td><td>{{ $service->driver_type->value }}<span class="admin-ui-meta">{{ $service->upstreamApi?->name ?? '本地资源' }}@if($service->upstream_instance_id !== null) · 上游 #{{ $service->upstream_instance_id }}@endif</span></td><td><x-admin.badge :tone="$service->status === \App\Enums\InstanceStatus::ACTIVE ? 'success' : ($service->status === \App\Enums\InstanceStatus::SUSPENDED ? 'warning' : 'neutral')">{{ $instanceLabels[$service->status->value] }}</x-admin.badge></td><td>{{ $service->hostname ?? '—' }}<span class="admin-ui-meta">{{ $service->primary_ip ?? '—' }}</span></td><td class="admin-ui-nowrap">{{ $service->next_due_at?->format('Y-m-d H:i') ?? '—' }}</td><td class="admin-ui-row-actions">@if($service->driver_type === \App\Enums\DriverType::ZJMF_API)<a class="admin-ui-link" href="/admin/upstream-instances?instance={{ $service->id }}">实例详情</a>@else<span class="admin-ui-meta">元数据只读</span>@endif</td></tr>
            @empty<tr><td colspan="6" class="admin-ui-empty"><strong>暂无服务记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$services" />
    </x-admin.card>
    @endif

    @if($permissions['finance'])
    <x-admin.card title="账单记录"><x-slot:actions><a class="admin-ui-link" href="/admin/finance?user_id={{ $customer->id }}">查看全部账单</a></x-slot:actions>
        <x-admin.table label="客户账单记录"><x-slot:head><tr><th scope="col">账单</th><th scope="col">状态</th><th scope="col" class="admin-ui-number">账单总额</th><th scope="col">创建 / 入账时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($invoices as $invoice)<tr data-customer-invoice="{{ $invoice->id }}"><td>#{{ $invoice->id }}<span class="admin-ui-meta">{{ $invoice->invoice_no }}</span></td><td><x-admin.badge :tone="$invoice->status === \App\Enums\InvoiceStatus::PAID ? 'success' : ($invoice->status === \App\Enums\InvoiceStatus::UNPAID ? 'warning' : 'neutral')">{{ $invoiceLabels[$invoice->status->value] }}</x-admin.badge></td><td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="admin-ui-nowrap">{{ $invoice->created_at?->format('Y-m-d H:i') }}<span class="admin-ui-meta">{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '未入账' }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $invoice->id }}">账单详情</a></td></tr>
            @empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无账单记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$invoices" />
    </x-admin.card>
    <x-admin.card title="资金流水" description="仅展示原流水事实，金额方向由流水类型决定。"><x-slot:actions><a class="admin-ui-link" href="/admin/finance/ledger?user_id={{ $customer->id }}">查看全部流水</a></x-slot:actions>
        <x-admin.table label="客户资金流水"><x-slot:head><tr><th scope="col">流水 / 时间</th><th scope="col">类型 / 账单</th><th scope="col" class="admin-ui-number">金额</th><th scope="col" class="admin-ui-number">变动后可用 / 账单冻结</th></tr></x-slot:head>
            @forelse($ledger as $entry)<tr data-customer-ledger="{{ $entry->id }}"><td>#{{ $entry->id }}<span class="admin-ui-meta">{{ $entry->created_at?->format('Y-m-d H:i') }}</span></td><td>{{ $entry->type->value }}@if($entry->invoice_id)<span class="admin-ui-meta"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $entry->invoice_id }}">账单 #{{ $entry->invoice_id }}</a></span>@endif</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} CNY</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无流水记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$ledger" />
    </x-admin.card>
    @endif

    @if($permissions['support'])
    <x-admin.card title="工单记录"><x-slot:actions><a class="admin-ui-link" href="/admin/support?user_id={{ $customer->id }}">查看全部工单</a></x-slot:actions>
        <x-admin.table label="客户工单记录"><x-slot:head><tr><th scope="col">工单 / 主题</th><th scope="col">状态</th><th scope="col">更新时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($tickets as $ticket)<tr data-customer-ticket="{{ $ticket->id }}"><td class="admin-customers-name">#{{ $ticket->id }} · {{ $ticket->subject }}<span class="admin-ui-meta">{{ $ticket->instance_id === null ? '未关联服务' : '服务 #'.$ticket->instance_id }}</span></td><td><x-admin.badge :tone="$ticket->status === 'open' ? 'warning' : 'neutral'">{{ $ticketLabels[$ticket->status] ?? $ticket->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $ticket->updated_at?->format('Y-m-d H:i') }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/support?user_id={{ $customer->id }}&amp;edit={{ $ticket->id }}">工单详情</a></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无工单记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tickets" />
    </x-admin.card>
    @endif

    <x-admin.card title="实名历史" description="仅展示已有脱敏摘要与认证状态，不提供人工通过或驳回操作。">
        <x-admin.table label="客户实名历史"><x-slot:head><tr><th scope="col">申请 / 通道</th><th scope="col">脱敏摘要</th><th scope="col">状态</th><th scope="col">申请 / 通过时间</th></tr></x-slot:head>
            @forelse($kycRecords as $record)
                @php
                    $summary = $record->masked_summary ?? [];
                    $maskedName = $summary['name'] ?? null;
                    $last4 = $summary['id_last4'] ?? null;
                    $maskedName = is_string($maskedName) && str_contains($maskedName, '*') && preg_match('/^[\p{L}*·]{1,20}$/uD', $maskedName) ? $maskedName : '—';
                    $last4 = is_string($last4) && preg_match('/^[0-9]{3}[0-9Xx]$/D', $last4) ? $last4 : '—';
                @endphp
                <tr data-customer-kyc="{{ $record->id }}"><td>#{{ $record->id }}<span class="admin-ui-meta">{{ $record->driver }}</span></td><td>{{ $maskedName }}<span class="admin-ui-meta">证号后四位 {{ $last4 }}</span></td><td><x-admin.badge :tone="$record->status === 'verified' ? 'success' : ($record->status === 'pending' ? 'warning' : 'neutral')">{{ $kycLabels[$record->status] ?? $record->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $record->created_at?->format('Y-m-d H:i') }}<span class="admin-ui-meta">{{ $record->verified_at?->format('Y-m-d H:i') ?? '—' }}</span></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无实名记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$kycRecords" />
    </x-admin.card>

    @if($permissions['notifications'])
    <x-admin.card title="通知记录" description="仅展示投递元数据；已受理表示通道接受请求，不代表最终送达。"><x-slot:actions><a class="admin-ui-link" href="/admin/notifications?user_id={{ $customer->id }}">查看通知日志</a></x-slot:actions>
        <x-admin.table label="客户通知记录"><x-slot:head><tr><th scope="col">日志 / 事件</th><th scope="col">渠道</th><th scope="col">状态</th><th scope="col">时间</th></tr></x-slot:head>
            @forelse($notifications as $notice)<tr data-customer-notification="{{ $notice->id }}"><td>#{{ $notice->id }}<span class="admin-ui-meta">{{ $notice->event_key }}</span></td><td>{{ $channelLabels[$notice->channel] ?? $notice->channel }}</td><td><x-admin.badge :tone="$notice->status === 'sent' ? 'success' : (in_array($notice->status, ['failed', 'unknown'], true) ? 'warning' : 'neutral')">{{ $deliveryLabels[$notice->status] ?? $notice->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $notice->created_at }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无通知记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$notifications" />
    </x-admin.card>
    @endif

    @if($permissions['audit'])
    <x-admin.card title="账户自身审计" description="仅包含以此账户为对象的审计，不代表账单、服务等资源的完整跨域日志。"><x-slot:actions><a class="admin-ui-link" href="/admin/audit?{{ http_build_query(['subject_type' => \App\Models\User::class, 'subject_id' => $customer->id]) }}">查看账户审计</a></x-slot:actions>
        <x-admin.table label="客户账户审计"><x-slot:head><tr><th scope="col">审计 / 时间</th><th scope="col">动作 / 操作者</th><th scope="col">允许的变更字段</th></tr></x-slot:head>
            @forelse($audits as $audit)<tr data-customer-audit="{{ $audit->id }}"><td>#{{ $audit->id }}<span class="admin-ui-meta">{{ $audit->created_at }}</span></td><td>{{ $audit->action }}<span class="admin-ui-meta">{{ $audit->actor_type === 'system' ? '系统' : $audit->actor_type }} {{ $audit->actor_id === null ? '—' : '#'.$audit->actor_id }}</span></td><td class="admin-customers-audit"><details><summary>查看变更字段</summary><p>变更前：{{ $audit->before }}</p><p>变更后：{{ $audit->after }}</p></details></td></tr>
            @empty<tr><td colspan="3" class="admin-ui-empty"><strong>暂无账户自身审计</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$audits" />
    </x-admin.card>
    @endif
</div>
</x-filament-panels::page>
