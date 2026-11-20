@php
    $bindingOperation = in_array($operation, ['terminate_verified', 'transfer_verified'], true);
    $marker = $bindingOperation ? 'reconciliation-binding' : 'reconciliation-'.$operation;
    $restoreCase = $errors->any() && old('_admin_form') === $marker && filter_var(old('record_id'), FILTER_VALIDATE_INT) === $selected->id
        && (!$bindingOperation || old('operation') === $operation);
    $caseValue = fn ($key, $default = null) => $restoreCase && is_scalar(old($key, $default)) ? old($key, $default) : $default;
    $path = $bindingOperation ? 'binding' : $operation;
@endphp
<x-admin.dialog :id="'reconciliation-'.$operation.'-'.$selected->id" :title="$label.' · 案件 #'.$selected->id" :show-errors="$restoreCase" x-init="{{ $restoreCase ? '$nextTick(() => $el.showModal())' : '' }}">
    <form method="post" action="/admin/reconciliations/{{ $selected->id }}/{{ $path }}" class="inventory-form admin-operations-form">@csrf
        <input type="hidden" name="_admin_form" value="{{ $marker }}"><input type="hidden" name="record_id" value="{{ $selected->id }}">
        <input type="hidden" name="request_id" value="{{ $caseValue('request_id', (string) \Illuminate\Support\Str::ulid()) }}"><input type="hidden" name="expected_revision" value="{{ $caseValue('expected_revision', $selected->revision) }}">
        @if($bindingOperation)<input type="hidden" name="operation" value="{{ $operation }}">@endif
        @if($operation === 'terminate_verified')
            <label class="admin-operations-wide">历史原端点（仅缺原快照时填写）<input name="original_base_url" type="url" maxlength="2048" value="{{ $caseValue('original_base_url') }}"></label><label class="admin-operations-wide">历史原端点证明<textarea name="historical_endpoint_evidence" maxlength="1000"></textarea></label>
        @elseif($operation === 'transfer_verified')
            <label>实际接收主体<input name="recipient" required maxlength="255"></label><label>被移交目标资源<input name="target_resource" required maxlength="255" value="{{ $caseValue('target_resource') }}"></label><label>责任接受凭证编号<input name="acceptance_reference" required maxlength="255"></label><label>实际接受时间<input name="accepted_at" type="datetime-local" required value="{{ $caseValue('accepted_at') }}"></label><label class="admin-operations-wide"><input type="checkbox" name="responsibility_accepted" value="1" required @checked((string) $caseValue('responsibility_accepted') === '1')>已核实接收方接受该原资源及后续责任</label>
        @endif
        <label class="admin-operations-wide">{{ $operation === 'reopen' ? '推翻原结案的实际证据' : ($operation === 'payment' ? '原笔核验证据' : '原笔处置证据') }}<textarea name="evidence" required maxlength="1000"></textarea></label>
        @if($restoreCase)<p class="admin-operations-note">核验证据、接收主体和凭证编号不会回填，请重新输入适用字段。</p>@endif
        <label class="admin-operations-wide">原因<input name="reason" required maxlength="255" value="{{ $caseValue('reason') }}"></label>
        <label class="admin-operations-wide"><input type="checkbox" name="confirm" value="1" required @checked((string) $caseValue('confirm') === '1')>{{ $operation === 'reopen' ? '原结案证据已不成立，申请重新打开核对' : ($operation === 'payment' ? '已核实原回调，按当前状态登记实际结果' : '已核对原绑定及本次事实') }}</label>
        <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">{{ $label }}</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
    </form>
</x-admin.dialog>
