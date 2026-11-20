@extends('sales.layout')
@section('title', '服务详情')
@section('content')
@php
    $serviceTone = match($instance->status) {
        \App\Enums\InstanceStatus::ACTIVE => 'success',
        \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning',
        \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger',
        default => 'neutral',
    };
@endphp
<x-console.page-header :title="$instance->display_name" description="服务资料与交付信息以最近同步记录为准。">
    <x-slot:actions><x-console.badge :tone="$serviceTone">{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}</x-console.badge><a class="console-button is-secondary" href="/console/instances">返回我的服务</a></x-slot:actions>
</x-console.page-header>
<x-console.card title="基本信息" :description="'服务 #'.$instance->id">
    <dl class="console-kv">
        <dt>商品</dt><dd>{{ $instance->product->name }}</dd>
        <dt>服务状态</dt><dd>{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}（{{ $instance->status->value }}）</dd>
        <dt>计费周期</dt><dd>{{ $instance->billing_cycle->label() }}</dd>
        <dt>创建时间</dt><dd>{{ $instance->created_at }}</dd>
        <dt>最近同步</dt><dd>{{ $instance->last_synced_at ?? '等待同步' }}</dd>
    </dl>
    @if(app(\App\Services\DriverManager::class)->oneTimeDelivery($instance) !== null)<p><a href="/console/instances/{{ $instance->id }}/archive">归档一次性交付权益</a></p>@endif
</x-console.card>
@if($canControlMachine || $canOpenPanel)
<x-console.card title="机器与控制面板" description="操作受理后可通过机器状态与监控核对结果。">
    <div class="console-actions">
        @if($canControlMachine && ($instance->active_config['upstream_panel']['machine_status'] ?? false))
            @include('sales.power-status-form')
        @endif
        @if($canControlMachine)
            <form method="post" action="/console/instances/{{ $instance->id }}/machine" data-machine-controls-read>@csrf<button type="submit">机器管理：电源、重装系统、救援、VNC</button><p role="status" hidden>正在读取机器管理配置，请稍候…</p></form>
            <script src="/js/machine-controls.js" defer></script>
        @endif
        @if($canOpenPanel && !isset($instance->encrypted_credentials['card_key']) && ($instance->active_config['upstream_panel']['information'] ?? true))
            <form method="post" action="/console/instances/{{ $instance->id }}/panel-login" target="_blank" rel="noopener noreferrer">@csrf<button type="submit">登录面板</button></form>
        @endif
        @if($canOpenPanel && (($instance->active_config['upstream_panel']['machine_status'] ?? false) || !empty($instance->active_config['upstream_panel']['charts'])))
            <a class="console-button is-secondary" href="/console/instances/{{ $instance->id }}/telemetry">查看机器状态与监控图表</a>
        @endif
    </div>
</x-console.card>
@endif
<x-console.card title="交付信息" description="请妥善保管服务登录资料和兑换凭证。">
    @if($instance->status === \App\Enums\InstanceStatus::ACTIVE && isset($instance->encrypted_credentials['card_key']))
        <dl class="console-kv"><dt>卡密</dt><dd><code>{{ $instance->encrypted_credentials['card_key'] }}</code></dd>
            @if(isset($instance->encrypted_credentials['face_value']))<dt>兑换面额</dt><dd>{{ $instance->encrypted_credentials['face_value'] }} 单位</dd>@endif
        </dl>
        @if(isset($instance->encrypted_credentials['redeem_url']))<p><a class="console-button is-secondary" href="{{ $instance->encrypted_credentials['redeem_url'] }}" rel="noreferrer">前往 Sub2API 兑换</a></p>@endif
    @elseif($instance->status === \App\Enums\InstanceStatus::ACTIVE)
        <dl class="console-kv"><dt>IP</dt><dd>{{ $instance->primary_ip ?? '等待同步' }}</dd>
            @if(isset($instance->encrypted_credentials['username']))<dt>用户名</dt><dd>{{ $instance->encrypted_credentials['username'] }}</dd>@endif
            @if(isset($instance->encrypted_credentials['port']))<dt>连接信息</dt><dd>登录端口：{{ $instance->encrypted_credentials['port'] }}</dd>@endif
            @if(isset($instance->encrypted_credentials['os']))<dt>操作系统</dt><dd>{{ $instance->encrypted_credentials['os'] }}</dd>@endif
            @if(isset($instance->encrypted_credentials['password']))<dt>密码</dt><dd><code>{{ $instance->encrypted_credentials['password'] }}</code></dd>@endif
        </dl>
        @if(!$instance->encrypted_credentials)<p class="console-muted">开通信息同步中，请稍后刷新。</p>@endif
    @else
        <p class="console-muted">尚未完成交付，请稍后刷新；未交付的订单请联系管理员。</p>
    @endif
</x-console.card>
@if($orderFields['fields'] !== [] || $orderFields['unavailable'])
<x-console.card title="下单资料" description="以下为下单时填写的资料，不代表上游当前配置。">
    @if($orderFields['unavailable'])<p class="console-muted">历史下单资料暂不可用。</p>
    @else<dl class="console-kv">@foreach($orderFields['fields'] as $field)<dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd>@endforeach</dl>@endif
</x-console.card>
@endif
@if($instance->billing_cycle->isRenewable() && $instance->status->isBillable())
<x-console.card title="续费与服务操作" description="续费和升级先核价；终止和价格调整请核对具体影响。">
    <div class="console-stats"><div class="console-stat"><span class="console-muted">下次到期</span><strong class="console-date">{{ $instance->next_due_at ?? '未设置' }}</strong></div><div class="console-stat"><span class="console-muted">续费价格</span><strong class="console-money">{{ $instance->recurring_amount }} {{ $instance->currency }}</strong></div></div>
    @if($renewalRecoveryInfo)
        @include('sales.renewal-recovery-status', ['recoveryInfo' => $renewalRecoveryInfo, 'invoice' => $renewalRecoveryInfo['current']])
    @endif
    @foreach(['renew' => '续费', 'upgrade' => '配置升级', 'downgrade' => '仅降低未来续费价格', 'terminate' => '申请终止'] as $action => $label)
        @php
            $operationForm = 'service-operation-'.$instance->id.'-'.$action;
            $restoreOperation = $errors->any() && old('ui_form') === $operationForm;
            $operationValue = static function (string $key, string $default = '') use ($restoreOperation): string {
                $value = $restoreOperation ? old($key, $default) : $default;
                return is_scalar($value) ? (string) $value : $default;
            };
            $operationRequest = $operationValue('request_id');
            $operationRequest = \Illuminate\Support\Str::isUlid($operationRequest) ? $operationRequest : (string) \Illuminate\Support\Str::ulid();
        @endphp
        <details @if($action === 'renew' || $restoreOperation) open @endif><summary>{{ $label }}</summary>
            <form method="post" action="/console/instances/{{ $instance->id }}/operations" class="console-form-grid">@csrf
                <input type="hidden" name="ui_form" value="{{ $operationForm }}"><input type="hidden" name="request_id" value="{{ $operationRequest }}"><input type="hidden" name="action" value="{{ $action }}">
                @if($action === 'renew')<label>优惠券码（先在客户中心领取）<input name="coupon_code" maxlength="64" value="{{ $operationValue('coupon_code') }}"></label>@endif
                @if(in_array($action, ['upgrade', 'downgrade'], true))
                    @if($instance->active_config['mapping_requires_review'] ?? false)<p>热切换后的配置映射待核对，升级与续费降价暂不可用。</p>@else
                        @if($instance->active_config['price_requires_review'] ?? false)<p>未来价格基线待本人确认，升级与续费降价暂不可用。<a href="/console/instances/{{ $instance->id }}/mapping-price">预览并确认完整配置的新续费价</a></p>@endif
                        @include('sales.config-options', ['product' => $instance->product, 'selection' => ['options' => ['configoption' => $instance->active_config['upstream_configoption'] ?? []]], 'serviceChange' => true])
                    @endif
                @endif
                @if(in_array($action, ['renew', 'upgrade'], true))
                    <label><input name="use_credit" type="checkbox" value="1">使用钱包（足够时立即结算）</label>
                    <label>其余金额支付方式<select name="gateway">@include('sales.payment-options', ['selectedGateway' => $operationValue('gateway')])</select></label>
                    @if($action === 'upgrade')<p class="console-muted">升级会先创建差价账单。</p>@endif
                    <label><input name="confirm" type="checkbox" value="1" required>确认{{ $label }}及本次钱包支付授权</label>
                @else
                    @if($action === 'downgrade')<p>仅降低未来续费价，本期不退款、远程配置不变。</p>@else<p class="error">终止可能永久删除数据，以实际删除确认为准。</p>@endif
                    <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000" value="{{ $operationValue('reason') }}"></label></details>
                    <label><input name="confirm" type="checkbox" value="1" required>确认{{ $label }}{{ $action === 'terminate' ? '，并知悉可能永久删除数据' : '' }}</label>
                @endif
                <button @class(['is-danger' => $action === 'terminate'])>{{ $action === 'terminate' ? '确认申请终止' : ($action === 'downgrade' ? '确认调整未来价格' : '核价并'.$label) }}</button>
            </form>
        </details>
    @endforeach
</x-console.card>
@endif
<x-console.card title="关联账单" description="仅列出当前服务在本账户中的账单。">
    @include('sales.invoice-list')
    {{ $invoices->links('sales.pagination') }}
</x-console.card>
@endsection
