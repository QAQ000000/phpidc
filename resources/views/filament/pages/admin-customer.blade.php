<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-customers.css?v=20261120-c3">
@php
    $closed = $customer->trashed() || $customer->anonymized_at !== null;
    $disabled = $customer->disabled_at !== null;
    $restoreContext = $errors->any() && is_string(old('_admin_form')) && is_scalar(old('customer_id')) && (string) old('customer_id') === (string) $customer->id ? old('_admin_form') : null;
    $restoreProfile = $management['profile'] && $restoreContext === 'customer-profile';
    $restoreReset = $management['password_reset'] && $restoreContext === 'customer-password-reset';
    $restoreAccess = $management['access'] && $restoreContext === 'customer-access';
    $restoreWallet = $walletAdjustment && $restoreContext === 'wallet-adjust';
    $oldValue = function ($key, $default = '') {
        $value = old($key, $default);
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $profileValue = fn ($key, $default = '') => $restoreProfile ? $oldValue($key, $default) : $default;
    $walletValue = fn ($key, $default = '') => $restoreWallet ? $oldValue($key, $default) : $default;
    $walletRequest = $walletValue('request_id');
    $walletRequest = is_string($walletRequest) && \Illuminate\Support\Str::isUlid($walletRequest) ? $walletRequest : (string) \Illuminate\Support\Str::ulid();
    $instanceLabels = ['Pending' => '待开通', 'Active' => '运行中', 'Suspended' => '已暂停', 'Cancelled' => '已取消', 'Fraud' => '异常', 'Deleted' => '已删除', 'ProvisionFailed' => '开通失败'];
    $invoiceLabels = ['Unpaid' => '待付款', 'Paid' => '已付款', 'Refunded' => '已退款', 'Cancelled' => '已取消', 'Expired' => '已过期'];
    $kycLabels = ['pending' => '待核对', 'verified' => '已认证', 'failed' => '未通过'];
    $ticketLabels = ['open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭'];
    $channelLabels = ['email' => '邮件', 'mail' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram'];
    $deliveryLabels = ['sent' => '已受理', 'failed' => '失败', 'simulated' => '模拟，未外发', 'pending' => '待发送', 'unknown' => '结果待核对', 'cancelled' => '已取消', 'skipped' => '已跳过', 'disabled' => '已停用'];
@endphp
<div class="admin-ui admin-customers-page" x-data>
    @if(session('customer_status'))<p role="status" class="admin-customers-feedback">{{ session('customer_status') }}</p>@endif
    @if($errors->any() && ! $restoreProfile && ! $restoreReset && ! $restoreAccess && ! $restoreWallet)<div role="alert" class="admin-customers-feedback">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>客户资料与本地业务记录，各区块独立分页；管理操作按权限开放。</p></div><div class="admin-ui-actions"><a class="admin-ui-button" href="/admin/customers{{ $closed ? '?status=closed' : ($disabled ? '?status=disabled' : '') }}">返回客户列表</a>@if($permissions['access'] && ! $closed)<a class="admin-ui-button" href="/admin/access?user_id={{ $customer->id }}">账户角色</a>@endif</div></header>
    <x-admin.card id="customer-profile" :title="'客户 #'.$customer->id">
        @if(in_array(true, $management, true))
        <x-slot:actions>
            @if($management['profile'])<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="customer-profile-editor" x-on:click="document.getElementById('customer-profile-editor').showModal()">编辑资料</button>@endif
            @if($management['password_reset'])<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="customer-password-reset" x-on:click="document.getElementById('customer-password-reset').showModal()">发送重置链接</button>@endif
            @if($management['access'])<button type="button" @class(['admin-ui-button', 'admin-customers-danger' => ! $disabled]) aria-haspopup="dialog" aria-controls="customer-access-editor" x-on:click="document.getElementById('customer-access-editor').showModal()">{{ $disabled ? '恢复账户' : '停用账户' }}</button>@endif
        </x-slot:actions>
        @endif
        <div class="admin-ui-card-body">
            <div class="admin-customers-identity"><strong>{{ $customer->name }}</strong><x-admin.badge :tone="$closed ? 'neutral' : ($disabled ? 'warning' : 'success')">{{ $closed ? '已注销' : ($disabled ? '已停用' : '正常') }}</x-admin.badge><x-admin.badge :tone="$customer->kyc_verified_at ? 'success' : 'neutral'">{{ $customer->kyc_verified_at ? '实名已认证' : '实名未认证' }}</x-admin.badge></div>
            <dl class="admin-ui-facts admin-customers-profile" style="margin-top:20px"><div><dt>邮箱</dt><dd>{{ $customer->email }}<span class="admin-ui-meta">{{ $customer->email_verified_at ? '邮箱已验证' : '邮箱未验证' }}</span></dd></div><div><dt>手机号</dt><dd>{{ $customer->phone ?? '未绑定' }}</dd></div><div><dt>注册时间</dt><dd>{{ $customer->created_at?->format('Y-m-d H:i:s') }}</dd></div></dl>
        </div>
        @if($permissions['finance'] || $permissions['services'])<dl class="admin-customers-metrics">
            @if($permissions['finance'])<div class="admin-customers-metric"><dt>可用余额</dt><dd data-customer-metric="credit">{{ $customer->credit }}<small>CNY</small></dd></div><div class="admin-customers-metric"><dt>账单冻结</dt><dd data-customer-metric="frozen_credit">{{ $customer->frozen_credit }}<small>CNY</small></dd></div><div class="admin-customers-metric"><dt>结清冻结</dt><dd data-customer-metric="wallet_clearance_frozen">{{ $customer->wallet_clearance_frozen }}<small>CNY</small></dd></div>@endif
            @if($permissions['services'])<div class="admin-customers-metric"><dt>本地有效服务</dt><dd data-customer-metric="active_services">{{ $customer->active_services_count }}</dd></div>@endif
        </dl>@endif
        @if($walletAdjustment)<div class="admin-customers-wallet-actions"><button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="customer-wallet-adjust" x-on:click="document.getElementById('customer-wallet-adjust').showModal()">钱包加扣款</button><span>仅调整可用余额，不改变冻结、佣金或既有账单。</span></div>@endif
        @if($permissions['finance'])<p class="admin-customers-notice">钱包三列保留各自含义；本页未执行应收核验，不据此判断客户是否欠款。</p>@endif
        @if($closed)<p class="admin-customers-notice">已注销账户仅保留匿名化资料及历史业务事实。</p>@endif
        @if($disabled && ! $closed)<p class="admin-customers-notice">此账户已停用访问；余额、账单和服务保留，已付款履约及自动生命周期继续按原业务处理。</p>@endif
    </x-admin.card>

    <nav class="admin-customers-jumps" aria-label="客户详情页内定位"><span>跳转：</span><a href="#customer-profile">资料</a>@if($permissions['services'])<a href="#customer-services">服务</a>@endif @if($permissions['finance'])<a href="#customer-invoices">账单</a><a href="#customer-ledger">流水</a>@endif @if($permissions['support'])<a href="#customer-tickets">工单</a>@endif<a href="#customer-kyc">实名</a>@if($permissions['notifications'])<a href="#customer-notifications">通知</a>@endif @if($permissions['audit'])<a href="#customer-audit">审计</a>@endif</nav>

    @if($permissions['services'])
    <x-admin.card id="customer-services" title="服务记录" description="本地有效服务为运行中或已暂停记录；其他状态作为历史事实保留。">
        <x-slot:actions><a class="admin-ui-link" href="/admin/upstream-instances?user_id={{ $customer->id }}">查看经典服务列表</a></x-slot:actions>
        <x-admin.table label="客户服务记录"><x-slot:head><tr><th scope="col">服务 / 名称</th><th scope="col">驱动 / 连接</th><th scope="col">状态</th><th scope="col">主机 / IP</th><th scope="col">到期时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($services as $service)<tr data-customer-service="{{ $service->id }}"><td class="admin-customers-name">#{{ $service->id }} · {{ $service->display_name }}</td><td>{{ $service->driver_type->value }}<span class="admin-ui-meta">{{ $service->upstreamApi?->name ?? '本地资源' }}@if($service->upstream_instance_id !== null) · 上游 #{{ $service->upstream_instance_id }}@endif</span></td><td><x-admin.badge :tone="$service->status === \App\Enums\InstanceStatus::ACTIVE ? 'success' : ($service->status === \App\Enums\InstanceStatus::SUSPENDED ? 'warning' : 'neutral')">{{ $instanceLabels[$service->status->value] }}</x-admin.badge></td><td>{{ $service->hostname ?? '—' }}<span class="admin-ui-meta">{{ $service->primary_ip ?? '—' }}</span></td><td class="admin-ui-nowrap">{{ $service->next_due_at?->format('Y-m-d H:i') ?? '—' }}</td><td class="admin-ui-row-actions">@if($service->driver_type === \App\Enums\DriverType::ZJMF_API)<a class="admin-ui-link" href="/admin/upstream-instances?instance={{ $service->id }}">实例详情</a>@else<span class="admin-ui-meta">元数据只读</span>@endif <a class="admin-ui-link" href="/admin/instances/{{ $service->id }}">实例工作台</a></td></tr>
            @empty<tr><td colspan="6" class="admin-ui-empty"><strong>暂无服务记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$services" />
    </x-admin.card>
    @endif

    @if($permissions['finance'])
    <x-admin.card id="customer-invoices" title="账单记录"><x-slot:actions><a class="admin-ui-link" href="/admin/finance?user_id={{ $customer->id }}">查看全部账单</a></x-slot:actions>
        <x-admin.table label="客户账单记录"><x-slot:head><tr><th scope="col">账单</th><th scope="col">状态</th><th scope="col" class="admin-ui-number">账单总额</th><th scope="col">创建 / 入账时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($invoices as $invoice)<tr data-customer-invoice="{{ $invoice->id }}"><td>#{{ $invoice->id }}<span class="admin-ui-meta">{{ $invoice->invoice_no }}</span></td><td><x-admin.badge :tone="$invoice->status === \App\Enums\InvoiceStatus::PAID ? 'success' : ($invoice->status === \App\Enums\InvoiceStatus::UNPAID ? 'warning' : 'neutral')">{{ $invoiceLabels[$invoice->status->value] }}</x-admin.badge></td><td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="admin-ui-nowrap">{{ $invoice->created_at?->format('Y-m-d H:i') }}<span class="admin-ui-meta">{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '未入账' }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $invoice->id }}">账单详情</a></td></tr>
            @empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无账单记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$invoices" />
    </x-admin.card>
    <x-admin.card id="customer-ledger" title="资金流水" description="仅展示原流水事实，金额方向由流水类型决定。"><x-slot:actions><a class="admin-ui-link" href="/admin/finance/ledger?user_id={{ $customer->id }}">查看全部流水</a></x-slot:actions>
        <x-admin.table label="客户资金流水"><x-slot:head><tr><th scope="col">流水 / 时间</th><th scope="col">类型 / 账单</th><th scope="col" class="admin-ui-number">金额</th><th scope="col" class="admin-ui-number">变动后可用 / 账单冻结</th></tr></x-slot:head>
            @forelse($ledger as $entry)<tr data-customer-ledger="{{ $entry->id }}"><td>#{{ $entry->id }}<span class="admin-ui-meta">{{ $entry->created_at?->format('Y-m-d H:i') }}</span></td><td>{{ match($entry->type->value) { 'wallet_adjust_credit' => '钱包加款', 'wallet_adjust_debit' => '钱包扣款', default => $entry->type->value } }}@if($entry->invoice_id)<span class="admin-ui-meta"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $entry->invoice_id }}">账单 #{{ $entry->invoice_id }}</a></span>@endif</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} CNY</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无流水记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$ledger" />
    </x-admin.card>
    @endif

    @if($permissions['support'])
    <x-admin.card id="customer-tickets" title="工单记录"><x-slot:actions><a class="admin-ui-link" href="/admin/support?user_id={{ $customer->id }}">查看全部工单</a></x-slot:actions>
        <x-admin.table label="客户工单记录"><x-slot:head><tr><th scope="col">工单 / 主题</th><th scope="col">状态</th><th scope="col">更新时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($tickets as $ticket)<tr data-customer-ticket="{{ $ticket->id }}"><td class="admin-customers-name">#{{ $ticket->id }} · {{ $ticket->subject }}<span class="admin-ui-meta">{{ $ticket->instance_id === null ? '未关联服务' : '服务 #'.$ticket->instance_id }}</span></td><td><x-admin.badge :tone="$ticket->status === 'open' ? 'warning' : 'neutral'">{{ $ticketLabels[$ticket->status] ?? $ticket->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $ticket->updated_at?->format('Y-m-d H:i') }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/support?user_id={{ $customer->id }}&amp;edit={{ $ticket->id }}">工单详情</a></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无工单记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tickets" />
    </x-admin.card>
    @endif

    <x-admin.card id="customer-kyc" title="实名历史" description="仅展示已有脱敏摘要与认证状态，不提供人工通过或驳回操作。">
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
    <x-admin.card id="customer-notifications" title="通知记录" description="仅展示投递元数据；已受理表示通道接受请求，不代表最终送达。"><x-slot:actions><a class="admin-ui-link" href="/admin/notifications?user_id={{ $customer->id }}">查看通知日志</a></x-slot:actions>
        <x-admin.table label="客户通知记录"><x-slot:head><tr><th scope="col">日志 / 事件</th><th scope="col">渠道</th><th scope="col">状态</th><th scope="col">时间</th></tr></x-slot:head>
            @forelse($notifications as $notice)<tr data-customer-notification="{{ $notice->id }}"><td>#{{ $notice->id }}<span class="admin-ui-meta">{{ $notice->event_key }}</span></td><td>{{ $channelLabels[$notice->channel] ?? $notice->channel }}</td><td><x-admin.badge :tone="$notice->status === 'sent' ? 'success' : (in_array($notice->status, ['failed', 'unknown'], true) ? 'warning' : 'neutral')">{{ $deliveryLabels[$notice->status] ?? $notice->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $notice->created_at }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无通知记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$notifications" />
    </x-admin.card>
    @endif

    @if($permissions['audit'])
    <x-admin.card id="customer-audit" title="账户自身审计" description="仅包含以此账户为对象的审计，不代表账单、服务等资源的完整跨域日志。"><x-slot:actions><a class="admin-ui-link" href="/admin/audit?{{ http_build_query(['subject_type' => \App\Models\User::class, 'subject_id' => $customer->id]) }}">查看账户审计</a></x-slot:actions>
        <x-admin.table label="客户账户审计"><x-slot:head><tr><th scope="col">审计 / 时间</th><th scope="col">动作 / 操作者</th><th scope="col">允许的变更字段</th></tr></x-slot:head>
            @forelse($audits as $audit)<tr data-customer-audit="{{ $audit->id }}"><td>#{{ $audit->id }}<span class="admin-ui-meta">{{ $audit->created_at }}</span></td><td>{{ $audit->action }}<span class="admin-ui-meta">{{ $audit->actor_type === 'system' ? '系统' : $audit->actor_type }} {{ $audit->actor_id === null ? '—' : '#'.$audit->actor_id }}</span></td><td class="admin-customers-audit"><details><summary>查看变更字段</summary><p>变更前：{{ $audit->before }}</p><p>变更后：{{ $audit->after }}</p></details></td></tr>
            @empty<tr><td colspan="3" class="admin-ui-empty"><strong>暂无账户自身审计</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$audits" />
    </x-admin.card>
    @endif

    @if($walletAdjustment)
    <x-admin.dialog id="customer-wallet-adjust" title="客户钱包加扣款" description="用于补差、补偿或纠错，只改变可用余额；不代表充值、退款或应收清偿。扣款不能超过可用余额。" :show-errors="$restoreWallet" x-init="{{ $restoreWallet ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="admin-customers-form" method="post" action="/admin/customers/{{ $customer->id }}/wallet-adjust">@csrf
            <input type="hidden" name="_admin_form" value="wallet-adjust"><input type="hidden" name="customer_id" value="{{ $customer->id }}"><input type="hidden" name="request_id" value="{{ $walletRequest }}"><input type="hidden" name="currency" value="CNY">
            <p class="admin-customers-wide">客户 #{{ $customer->id }} · {{ $customer->name }} · 当前可用 {{ $customer->credit }} CNY</p>
            <label>调整方向<select name="direction" required><option value="credit" @selected($walletValue('direction') !== 'debit')>增加可用余额</option><option value="debit" @selected($walletValue('direction') === 'debit')>扣减可用余额</option></select></label>
            <label>金额（CNY）<input name="amount" type="text" inputmode="decimal" pattern="[0-9]{1,10}\.[0-9]{2}" placeholder="0.00" required maxlength="13" value="{{ $walletValue('amount') }}"></label>
            <label class="admin-customers-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $walletValue('reason') }}"></label>
            <label class="admin-customers-wide"><span><input type="checkbox" name="confirm" value="1" required> 已核对客户、方向及金额，确认仅调整可用余额。</span></label>
            <div class="admin-customers-form-actions admin-customers-wide"><button class="admin-ui-button admin-ui-button-primary">确认加扣款</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
    @if($management['profile'])
    <x-admin.dialog id="customer-profile-editor" title="编辑客户资料" description="邮箱或手机号改变后需要重新验证，原有登录会话将退出；仅修改姓名不会退出登录。" :show-errors="$restoreProfile" x-init="{{ $restoreProfile ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="admin-customers-form" method="post" action="/admin/customers/{{ $customer->id }}/profile">@csrf
            <input type="hidden" name="_admin_form" value="customer-profile"><input type="hidden" name="customer_id" value="{{ $customer->id }}">
            <label>姓名<input name="name" required maxlength="255" value="{{ $profileValue('name', $customer->name) }}" autocomplete="off"></label>
            <label>手机号（可清空）<input name="phone" type="tel" maxlength="16" pattern="\+[1-9][0-9]{7,14}" value="{{ $profileValue('phone', $customer->phone ?? '') }}" placeholder="+8613800000000" autocomplete="off"></label>
            <label class="admin-customers-wide">邮箱<input name="email" type="email" required maxlength="255" value="{{ $profileValue('email', $customer->email) }}" autocomplete="off"></label>
            <label class="admin-customers-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $profileValue('reason') }}"></label>
            <div class="admin-customers-form-actions admin-customers-wide"><button class="admin-ui-button admin-ui-button-primary">保存资料</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
    @if($management['password_reset'])
    <x-admin.dialog id="customer-password-reset" title="发送密码重置链接" description="向当前登记邮箱发送标准重置链接，60 分钟内有效；发送请求受理不代表邮件已送达，结果不明确时请先核对。" :show-errors="$restoreReset" x-init="{{ $restoreReset ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="admin-customers-form" method="post" action="/admin/customers/{{ $customer->id }}/password-reset">@csrf
            <input type="hidden" name="_admin_form" value="customer-password-reset"><input type="hidden" name="customer_id" value="{{ $customer->id }}">
            <p class="admin-customers-wide">登记邮箱：<strong>{{ $customer->email }}</strong></p>
            <label class="admin-customers-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $restoreReset ? $oldValue('reason') : '' }}"></label>
            <div class="admin-customers-form-actions admin-customers-wide"><button class="admin-ui-button admin-ui-button-primary">发送重置链接</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
    @if($management['access'])
    <x-admin.dialog id="customer-access-editor" :title="$disabled ? '恢复账户访问' : '停用账户访问'" :description="$disabled ? '恢复后客户须重新登录。旧会话和旧验证证明不会恢复；历史业务记录保持原归属。' : '停用将退出已有会话并禁止登录及新的人为购买、充值和支付操作；余额、账单、服务、已付款履约及自动生命周期保留。'" :show-errors="$restoreAccess" x-init="{{ $restoreAccess ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="admin-customers-form" method="post" action="/admin/customers/{{ $customer->id }}/access">@csrf
            <input type="hidden" name="_admin_form" value="customer-access"><input type="hidden" name="customer_id" value="{{ $customer->id }}"><input type="hidden" name="disabled" value="{{ $disabled ? '0' : '1' }}">
            <p class="admin-customers-wide">客户 #{{ $customer->id }} · {{ $customer->name }}</p>
            <label class="admin-customers-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $restoreAccess ? $oldValue('reason') : '' }}"></label>
            <div class="admin-customers-form-actions admin-customers-wide"><button @class(['admin-ui-button', 'admin-ui-button-primary' => $disabled, 'admin-customers-danger' => ! $disabled])>{{ $disabled ? '恢复账户访问' : '停用账户访问' }}</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
</div>
</x-filament-panels::page>
