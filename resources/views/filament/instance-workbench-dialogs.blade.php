@php
    $controlForm = 'instance-control-'.$instance->id;
    $swapForm = 'instance-hotswap-'.$instance->id;
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
@if($canLifecycle)
    <x-admin.dialog id="instance-lifecycle" title="服务状态操作" description="操作进入现有任务流程；终止可能永久删除数据。" :show-errors="$restoreControl" x-init="{{ $restoreControl ? '$nextTick(() => $el.showModal())' : 'if (window.location.hash === \'#instance-lifecycle\') $nextTick(() => $el.showModal())' }}">
        <form class="admin-instance-form" method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/control') }}">@csrf
            <input type="hidden" name="ui_form" value="{{ $controlForm }}"><input type="hidden" name="request_id" value="{{ $controlRequest }}">
            <label>操作<select name="action"><option value="suspend" @selected($controlAction === 'suspend')>暂停</option><option value="unsuspend" @selected($controlAction === 'unsuspend')>恢复</option><option value="terminate" @selected($controlAction === 'terminate')>申请终止</option></select></label>
            <label>备注（选填）<input name="reason" maxlength="1000" value="{{ $submittedValue($restoreControl, 'reason') }}"></label>
            <label><span><input type="checkbox" name="confirm" value="1" required>确认操作（终止可能永久删除数据）</span></label>
            <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">提交操作</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@endif
@if($canHotSwap)
    <x-admin.dialog id="instance-hot-swap" title="显式热切换绑定" description="仅绑定已有目标主机，不迁移磁盘数据。选择终止旧资源时须确认其已删除；保留旧资源会记录孤儿告警。" :show-errors="$restoreSwap" x-init="{{ $restoreSwap ? '$nextTick(() => $el.showModal())' : 'if (window.location.hash === \'#instance-hot-swap\') $nextTick(() => $el.showModal())' }}">
        <form class="admin-instance-form" method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/hot-swap') }}">@csrf
            <input type="hidden" name="ui_form" value="{{ $swapForm }}">
            <label>目标连接<select name="account_id" required><option value="">请选择连接</option>@foreach($hotSwapAccounts as $account)<option value="{{ $account->id }}" @selected($submittedValue($restoreSwap, 'account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
            <label>目标主机 ID<input name="host_id" type="number" required value="{{ $submittedValue($restoreSwap, 'host_id') }}"></label>
            <p class="admin-ui-meta">目标商品从已认证的上游主机资料读取。</p>
            <label>旧资源处置<select name="disposition"><option value="keep_orphan" @selected($swapDisposition === 'keep_orphan')>保留旧资源并记录孤儿</option><option value="terminate" @selected($swapDisposition === 'terminate')>已终止且确认 Deleted</option></select></label>
            <label>原因<input name="reason" required maxlength="255" value="{{ $submittedValue($restoreSwap, 'reason') }}"></label>
            <label><span><input name="confirm" type="checkbox" value="1" required>已核对目标归属、币种、周期及旧资源处置</span></label>
            <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认切换</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@endif
