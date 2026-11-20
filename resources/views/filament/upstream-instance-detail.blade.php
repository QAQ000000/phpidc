@php
    $connection = $accounts->firstWhere('id', $selectedInstance->upstream_api_id);
    $controlForm = 'instance-control-'.$selectedInstance->id;
    $swapForm = 'instance-hotswap-'.$selectedInstance->id;
    $restoreControl = $errors->any() && old('ui_form') === $controlForm;
    $restoreSwap = $errors->any() && old('ui_form') === $swapForm;
    $submittedValue = static function (bool $restore, string $key, string $default = ''): string {
        $value = $restore ? old($key, $default) : $default;
        return is_scalar($value) ? (string) $value : $default;
    };
    $controlAction = $submittedValue($restoreControl, 'action', 'suspend');
    $controlAction = in_array($controlAction, ['suspend', 'unsuspend', 'terminate'], true) ? $controlAction : 'suspend';
    $controlRequest = $submittedValue($restoreControl, 'request_id');
    $controlRequest = \Illuminate\Support\Str::isUlid($controlRequest) ? $controlRequest : (string) \Illuminate\Support\Str::ulid();
    $swapDisposition = $submittedValue($restoreSwap, 'disposition', 'keep_orphan');
    $swapDisposition = in_array($swapDisposition, ['keep_orphan', 'terminate'], true) ? $swapDisposition : 'keep_orphan';
@endphp
<x-admin.card :title="$selectedInstance->display_name" description="执行操作前请核对当前服务、目标资源和处置方式。">
    <x-slot:actions><a class="admin-ui-button" href="{{ $detailReturnUrl }}">返回实例列表</a><a class="admin-ui-button" href="/admin/machine-operation-review?instance_id={{ $selectedInstance->id }}">机器命令核对与历史</a></x-slot:actions>
    <div class="admin-ui-card-body">
        <dl class="admin-ui-facts">
            <div><dt>本地实例</dt><dd>#{{ $selectedInstance->id }}</dd></div>
            <div><dt>客户 / 商品</dt><dd>客户 #{{ $selectedInstance->user_id }} / 商品 #{{ $selectedInstance->product_id }}</dd></div>
            <div><dt>本地状态</dt><dd><x-admin.badge :title="$selectedInstance->status->value" :tone="match($selectedInstance->status) { \App\Enums\InstanceStatus::ACTIVE => 'success', \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning', \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger', default => 'neutral' }">{{ $statusLabels[$selectedInstance->status->value] }}</x-admin.badge></dd></div>
            <div><dt>上游连接 / 主机</dt><dd>{{ $connection?->name ?? '未关联连接' }} · #{{ $selectedInstance->upstream_api_id }} / {{ $selectedInstance->upstream_instance_id ?? '未绑定主机' }}@if($connection && !$connection->is_active)<span class="admin-ui-meta">连接停用</span>@endif</dd></div>
            <div><dt>主机名</dt><dd>{{ $selectedInstance->hostname ?? '未设置' }}</dd></div>
            <div><dt>主 IP</dt><dd>{{ $selectedInstance->primary_ip ?? '未分配' }}</dd></div>
            <div><dt>到期时间</dt><dd>{{ $selectedInstance->next_due_at?->format('Y-m-d H:i') ?? '未设置' }}</dd></div>
            <div><dt>续费金额</dt><dd>{{ $selectedInstance->recurring_amount }} {{ $selectedInstance->currency }} / {{ $selectedInstance->billing_cycle->label() }}</dd></div>
            <div><dt>最近同步时间</dt><dd>{{ $selectedInstance->last_synced_at?->format('Y-m-d H:i') ?? '未同步' }}</dd></div>
            <div><dt>暂停原因</dt><dd>{{ $selectedInstance->suspend_reason ?? '—' }}</dd></div>
        </dl>
        @can('upstream.mapping-review')<p class="inventory-note"><a class="admin-ui-link" href="/admin/upstream-instances/{{ $selectedInstance->id }}/mapping-review">配置映射核准与未来价格确认状态</a></p>@endcan
    </div>
</x-admin.card>
@can('upstream.lifecycle')
<x-admin.card title="服务状态操作" description="操作进入现有任务流程；终止可能永久删除数据。">
    <div class="admin-ui-card-body"><details @if($restoreControl) open @endif><summary>暂停、恢复或申请终止</summary>
        <form class="inventory-form" method="post" action="/admin/upstream-instances/{{ $selectedInstance->id }}/control">@csrf
            <input type="hidden" name="ui_form" value="{{ $controlForm }}"><input type="hidden" name="request_id" value="{{ $controlRequest }}">
            <label>操作<select name="action"><option value="suspend" @selected($controlAction === 'suspend')>暂停</option><option value="unsuspend" @selected($controlAction === 'unsuspend')>恢复</option><option value="terminate" @selected($controlAction === 'terminate')>申请终止</option></select></label>
            <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000" value="{{ $submittedValue($restoreControl, 'reason') }}"></label></details>
            <label><input type="checkbox" name="confirm" value="1" required>确认操作（终止可能永久删除数据）</label><button class="inventory-button">提交操作</button>
        </form>
    </details></div>
</x-admin.card>
@endcan
@can('upstream.hotswap')
<x-admin.card title="显式热切换绑定" description="仅绑定已有目标主机，不迁移磁盘数据。">
    <div class="admin-ui-card-body"><details @if($restoreSwap) open @endif><summary>核对并切换资源绑定</summary>
        <p class="inventory-note">选择终止旧资源时，必须先确认其已删除；保留旧资源会记录孤儿告警。</p>
        <form class="inventory-form" method="post" action="/admin/upstream-instances/{{ $selectedInstance->id }}/hot-swap">@csrf
            <input type="hidden" name="ui_form" value="{{ $swapForm }}">
            <label>目标连接<select name="account_id" required><option value="">请选择连接</option>@foreach($accounts->where('is_active', true) as $account)<option value="{{ $account->id }}" @selected($submittedValue($restoreSwap, 'account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
            <label>目标主机 ID<input name="host_id" type="number" required value="{{ $submittedValue($restoreSwap, 'host_id') }}"></label><p>目标商品将从上游认证后的主机资料读取，无需手填商品 ID。</p>
            <label>旧资源处置<select name="disposition"><option value="keep_orphan" @selected($swapDisposition === 'keep_orphan')>保留旧资源并记录孤儿</option><option value="terminate" @selected($swapDisposition === 'terminate')>已终止且确认 Deleted</option></select></label>
            <label>原因<input name="reason" required maxlength="255" value="{{ $submittedValue($restoreSwap, 'reason') }}"></label><label><input name="confirm" type="checkbox" value="1" required>已核对目标归属、币种、周期及旧资源处置</label><button class="inventory-button">确认切换</button>
        </form>
    </details></div>
</x-admin.card>
@endcan
