<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>按固定业务角色授撤权限。查看不等于允许操作；财务、库存、内容、上游、审计权限分别授予。</p><p class="inventory-note">权限管理员仅由服务器命令管理；此处不能变更本人角色、角色定义或直接权限。撤销一个角色会保留其他授权。</p>
<form method="get" class="inventory-form"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>精确邮箱<input name="email" type="email" value="{{ request('email') }}"></label><button class="inventory-button">查找账户</button><a class="inventory-link" href="/admin/access">重置</a></form>
<details><summary>查看固定角色权限</summary>@foreach($definitions as $role => $permissions)<p class="inventory-meta">{{ $role }}：{{ implode('、', $permissions) }}</p>@endforeach</details></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table inventory-tasks"><thead><tr><th>账户</th><th>当前角色</th><th>授撤权限</th></tr></thead><tbody>
@forelse($users as $user)<tr><td>#{{ $user->id }} {{ $user->name }}<br>{{ $user->email }}</td><td>{{ $user->roles->pluck('name')->implode('、') ?: '无后台角色' }}</td><td>
@can('access.manage')@if($user->id !== auth()->id())<form method="post" action="/admin/access/{{ $user->id }}/role" class="inventory-form">@csrf<label>固定角色<select name="role" required><option value="">请选择</option>@foreach($roles as $role)<option value="{{ $role }}">{{ $role }}</option>@endforeach</select></label><label>操作<select name="operation" required><option value="grant">授予</option><option value="revoke">撤销</option></select></label><label>操作原因<input name="reason" required maxlength="255"></label><label><span><input type="checkbox" name="confirm" value="1" required> 已核对用户和授权范围</span></label><button class="inventory-button">确认更新角色</button></form>@else 本人角色请通过服务器命令管理。@endif@endcan
</td></tr>@empty<tr><td colspan="3">没有匹配的有效账户。</td></tr>@endforelse
</tbody></table>{{ $users->links() }}</div>
</x-filament-panels::page>
