@php
    $formKey = 'classic-'.($account?->id ?? 'new');
    $restoreInput = old('ui_form') === $formKey;
    $formValue = function (string $key, mixed $default = '') use ($restoreInput): mixed {
        $value = $restoreInput ? old($key, $default) : $default;
        return is_scalar($value) || $value === null ? ($value ?? '') : $default;
    };
@endphp
<form class="inventory-form" method="post" action="/admin/upstreams/configure">@csrf
<input type="hidden" name="ui_dialog" value="upstream-connections"><input type="hidden" name="ui_form" value="{{ $formKey }}">
@if($account)<input type="hidden" name="account_id" value="{{ $account->id }}">@endif
<label>连接名称<input name="name" required maxlength="255" value="{{ $formValue('name', $account?->name) }}"></label>
<label>上游地址<input name="url" type="url" required value="{{ $formValue('url', $account?->base_url) }}"></label>
<label>客户账号<input name="username" required value="{{ $formValue('username', $account?->username) }}"></label>
<label>API 密钥{{ $account ? '（留空保留原密钥）' : '' }}<input type="password" name="api_secret" value="" @required(!$account) autocomplete="new-password"></label>
<input type="hidden" name="raw_jwt" value="0"><label><input type="checkbox" name="raw_jwt" value="1" @checked(in_array($formValue('raw_jwt', $account?->auth_header_mode === 'raw_jwt'), [true, 1, '1'], true))>使用原始 JWT 兼容模式</label>
<input type="hidden" name="credit_limit" value="0"><label><input type="checkbox" name="credit_limit" value="1" @checked(in_array($formValue('credit_limit', $account?->allow_credit_limit ?? false), [true, 1, '1'], true))>允许信用额度支付回退（会产生上游债务）</label>
<details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000" value="{{ $formValue('reason') }}"></label></details>
<button class="inventory-button">{{ $account ? '保存连接修改' : '新增加密连接' }}</button></form>
