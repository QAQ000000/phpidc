@extends('sales.layout')
@section('title', '服务详情')
@section('content')
<p><a href="/console/instances">返回我的服务</a></p><h1>{{ $instance->display_name }}</h1><p>状态：{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}（{{ $instance->status->value }}）</p>
<p>商品：{{ $instance->product->name }} · {{ $instance->billing_cycle->label() }}<br>创建：{{ $instance->created_at }}<br>最近同步：{{ $instance->last_synced_at ?? '等待同步' }}</p>
@if(app(\App\Services\DriverManager::class)->oneTimeDelivery($instance) !== null)
<p><a href="/console/instances/{{ $instance->id }}/archive">归档一次性交付权益</a></p>
@endif
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
<p><a href="/console/instances/{{ $instance->id }}/telemetry">查看机器状态与监控图表</a></p>
@endif
@if($instance->billing_cycle->isRenewable() && $instance->status->isBillable())
<p>下次到期：{{ $instance->next_due_at }}；续费价格 {{ $instance->recurring_amount }} {{ $instance->currency }}</p>
@if($renewalRecoveryInfo)
@include('sales.renewal-recovery-status', ['recoveryInfo' => $renewalRecoveryInfo, 'invoice' => $renewalRecoveryInfo['current']])
@endif
@foreach(['renew' => '续费', 'upgrade' => '配置升级', 'downgrade' => '仅降低未来续费价格', 'terminate' => '申请终止'] as $action => $label)
@if($action !== 'renew')<details><summary>{{ $label }}</summary>@endif
<form method="post" action="/console/instances/{{ $instance->id }}/operations">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="action" value="{{ $action }}">
@if($action === 'renew')<label>优惠券码（先在客户中心领取）<input name="coupon_code" maxlength="64"></label>@endif
@if(in_array($action, ['upgrade', 'downgrade'], true))
@if($instance->active_config['mapping_requires_review'] ?? false)<p>热切换后的配置映射待核对，升级与续费降价暂不可用。</p>@else
@if($instance->active_config['price_requires_review'] ?? false)<p>未来价格基线待本人确认，升级与续费降价暂不可用。<a href="/console/instances/{{ $instance->id }}/mapping-price">预览并确认完整配置的新续费价</a></p>@endif
@include('sales.config-options', ['product' => $instance->product, 'selection' => ['options' => ['configoption' => $instance->active_config['upstream_configoption'] ?? []]], 'serviceChange' => true])
@endif
@endif
@if(in_array($action, ['renew', 'upgrade'], true))
<label><input name="use_credit" type="checkbox" value="1">使用钱包（足够时立即结算）</label>
<label>其余金额支付方式<select name="gateway">@include('sales.payment-options')</select></label>
@if($action === 'upgrade')<p>升级会先创建差价账单。</p>@endif
<label><input name="confirm" type="checkbox" value="1" required>确认{{ $label }}及本次钱包支付授权</label>
@else
@if($action === 'downgrade')<p>仅降低未来续费价，本期不退款、远程配置不变。</p>@else<p>终止可能永久删除数据，以实际删除确认为准。</p>@endif
<details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details>
<label><input name="confirm" type="checkbox" value="1" required>确认{{ $label }}{{ $action === 'terminate' ? '，并知悉可能永久删除数据' : '' }}</label>
@endif
<button>{{ $action === 'terminate' ? '确认申请终止' : ($action === 'downgrade' ? '确认调整未来价格' : '核价并'.$label) }}</button>
</form>
@if($action !== 'renew')</details>@endif
@endforeach
@endif
@if($instance->status === \App\Enums\InstanceStatus::ACTIVE && isset($instance->encrypted_credentials['card_key']))<p>卡密：<code>{{ $instance->encrypted_credentials['card_key'] }}</code></p>
@if(isset($instance->encrypted_credentials['face_value']))<p>兑换面额：{{ $instance->encrypted_credentials['face_value'] }} 单位</p>@endif
@if(isset($instance->encrypted_credentials['redeem_url']))<p><a href="{{ $instance->encrypted_credentials['redeem_url'] }}" rel="noreferrer">前往 Sub2API 兑换</a></p>@endif
@elseif($instance->status === \App\Enums\InstanceStatus::ACTIVE)
<p>IP：{{ $instance->primary_ip ?? '等待同步' }}</p>
@if(isset($instance->encrypted_credentials['username']))<p>用户名：{{ $instance->encrypted_credentials['username'] }}</p>@endif
@if(isset($instance->encrypted_credentials['port']))<p>登录端口：{{ $instance->encrypted_credentials['port'] }}</p>@endif
@if(isset($instance->encrypted_credentials['os']))<p>操作系统：{{ $instance->encrypted_credentials['os'] }}</p>@endif
@if(isset($instance->encrypted_credentials['password']))<p>密码：<code>{{ $instance->encrypted_credentials['password'] }}</code></p>@endif
@if(!$instance->encrypted_credentials)<p>开通信息同步中，请稍后刷新。</p>@endif
@else<p>尚未完成交付，请稍后刷新；未交付的订单请联系管理员。</p>@endif
@if($orderFields['fields'] !== [] || $orderFields['unavailable'])
<h2>下单资料</h2><p>以下为下单时填写的资料，不代表上游当前配置。</p>
@if($orderFields['unavailable'])<p>历史下单资料暂不可用。</p>
@else<dl>@foreach($orderFields['fields'] as $field)<dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd>@endforeach</dl>@endif
@endif
<h2>关联账单</h2>@include('sales.invoice-list'){{ $invoices->links('sales.pagination') }}
@endsection
