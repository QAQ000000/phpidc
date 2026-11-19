<form class="inventory-form" method="post" action="/admin/upstreams/sub2api/configure">@csrf
@if($account)<input type="hidden" name="account_id" value="{{ $account->id }}">@endif
<label>连接名称<input name="name" required maxlength="255" value="{{ $account?->name }}"></label>
<label>Sub2API 站点地址<input name="url" type="url" required maxlength="255" value="{{ $account?->base_url }}" @readonly($account !== null)></label>
@if($account)<p>站点地址固定；更换站点请新增连接，以保留历史订单的原站点。</p>@endif
<label>管理员 API 密钥{{ $account ? '（留空保留原密钥）' : '' }}<input type="password" name="api_secret" @required(!$account) autocomplete="new-password" maxlength="4096"></label>
@if($account)<p>{{ $account->has_secret ? '管理员密钥已设置' : '管理员密钥尚未设置' }}</p>@endif
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($account?->is_active ?? true)>启用远程连接</label>
<p>停用将暂停新的发码和人工核对。已经发出的请求仍会保留结果，客户已交付的兑换码仍可查看。</p>
<details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="255"></label></details>
<button class="inventory-button">{{ $account ? '保存 Sub2API 连接修改' : '新增 Sub2API 加密连接' }}</button></form>
