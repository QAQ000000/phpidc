<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>按固定业务角色授撤权限。查看不等于允许操作；财务、库存、内容、上游、审计权限分别授予。</p><p class="inventory-note">权限管理员仅由服务器命令管理；此处不能变更本人角色、角色定义或直接权限。撤销一个角色会保留其他授权。</p>
<form method="get" class="inventory-form"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>精确邮箱<input name="email" type="email" value="{{ request('email') }}"></label><button class="inventory-button">查找账户</button><a class="inventory-link" href="/admin/access">重置</a></form>
<details><summary>查看固定角色权限</summary>@foreach($definitions as $role => $permissions)<p class="inventory-meta">{{ $roleLabels[$role] }}：{{ implode('、', $permissions) }}</p>@endforeach</details></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table inventory-tasks"><thead><tr><th>账户</th><th>当前角色</th><th>授撤权限</th></tr></thead><tbody>
@forelse($users as $user)<tr><td>#{{ $user->id }} {{ $user->name }}<br>{{ $user->email }}</td><td>{{ $user->roles->pluck('name')->map(fn ($role) => $roleLabels[$role] ?? $role)->implode('、') ?: '无后台角色' }}</td><td>
@can('access.manage')@if($user->id !== auth()->id())
@php($assignedRoles = $user->roles->pluck('name')->intersect($roles)->sort()->values()->all())
<details><summary>调整角色</summary>
<form method="post" action="/admin/access/{{ $user->id }}/roles" class="inventory-form" x-data="{ selectedRoles: @js($assignedRoles) }">@csrf
<input type="hidden" name="confirm" value="1">
@foreach($assignedRoles as $role)<input type="hidden" name="expected_roles[]" value="{{ $role }}">@endforeach
<p>为 #{{ $user->id }} {{ $user->name }} 选择角色，一次保存全部变更。</p>
<div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
@foreach($presets as $preset)<button type="button" class="inventory-button" x-on:click="selectedRoles = @js($preset['roles'])">{{ $preset['label'] }}</button>@endforeach
<button type="button" class="inventory-button" x-on:click="selectedRoles = []">清空选择</button>
</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.5rem;width:100%;">
@foreach($roles as $role)<label title="{{ $role }}"><span><input type="checkbox" name="roles[]" value="{{ $role }}" x-model="selectedRoles" @checked(in_array($role, $assignedRoles, true))> {{ $roleLabels[$role] }}</span></label>@endforeach
</div>
<label>备注（可选）<input name="reason" maxlength="255"></label><button class="inventory-button">保存角色</button>
</form></details>
@else 本人角色请通过服务器命令管理。@endif@endcan
</td></tr>@empty<tr><td colspan="3">没有匹配的有效账户。</td></tr>@endforelse
</tbody></table>{{ $users->links() }}</div>
</x-filament-panels::page>
