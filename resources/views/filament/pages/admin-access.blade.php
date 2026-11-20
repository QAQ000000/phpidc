<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>按固定业务角色授撤权限。查看不等于允许操作；财务、库存、内容、上游、审计权限分别授予。</p><p class="admin-operations-note">权限管理员仅由服务器命令管理；此处不能变更本人角色、角色定义或直接权限。撤销一个角色会保留其他授权。</p></div></header>
    <x-admin.card title="账户列表">
        <x-admin.filters action="/admin/access"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>精确邮箱<input name="email" type="email" maxlength="255" value="{{ request('email') }}"></label></x-admin.filters>
        <x-admin.table label="账户与当前角色"><x-slot:head><tr><th scope="col">账户</th><th scope="col">当前角色</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($users as $user)<tr><td class="admin-ui-product-name">{{ $user->name }}<span class="admin-ui-meta">用户 #{{ $user->id }} · {{ $user->email }}</span></td><td class="admin-operations-roles">{{ $user->roles->pluck('name')->map(fn ($role) => $roleLabels[$role] ?? $role)->implode('、') ?: '无后台角色' }}</td><td class="admin-ui-row-actions">
                @can('access.manage')@if($user->id !== auth()->id())<button type="button" class="admin-ui-button" x-on:click="document.getElementById('access-roles-{{ $user->id }}').showModal()">调整角色</button>@else<span class="admin-ui-meta">本人角色请通过服务器命令管理。</span>@endif@endcan
            </td></tr>@empty<tr><td colspan="3" class="admin-ui-empty"><strong>没有匹配的有效账户</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$users" />
    </x-admin.card>
    <x-admin.card title="固定角色权限说明"><div class="admin-ui-card-body"><details><summary>查看固定角色权限</summary>@foreach($definitions as $role => $permissions)<p class="admin-operations-note">{{ $roleLabels[$role] }}：{{ implode('、', $permissions) }}</p>@endforeach</details></div></x-admin.card>
    @can('access.manage')
        @foreach($users as $user)@if($user->id !== auth()->id())
            @php
                $assignedRoles = $user->roles->pluck('name')->intersect($roles)->sort()->values()->all();
                $restoreRoles = $errors->any() && old('_admin_form') === 'access-roles' && filter_var(old('record_id'), FILTER_VALIDATE_INT) === $user->id
                    && (!request()->filled('user_id') || request()->integer('user_id') === $user->id);
                $selectedRoles = $restoreRoles && is_array(old('roles', [])) ? array_values(array_intersect(array_filter(old('roles', []), 'is_string'), $roles)) : $assignedRoles;
                $expectedRoles = $restoreRoles && is_array(old('expected_roles', [])) ? array_values(array_intersect(array_filter(old('expected_roles', []), 'is_string'), $roles)) : $assignedRoles;
            @endphp
            <x-admin.dialog :id="'access-roles-'.$user->id" :title="'调整用户 #'.$user->id.' 的角色'" :show-errors="$restoreRoles" x-init="{{ $restoreRoles ? '$nextTick(() => $el.showModal())' : '' }}">
                <form method="post" action="/admin/access/{{ $user->id }}/roles" class="inventory-form admin-operations-form" x-data="{ selectedRoles: @js($selectedRoles) }">@csrf
                    <input type="hidden" name="_admin_form" value="access-roles"><input type="hidden" name="record_id" value="{{ $user->id }}"><input type="hidden" name="confirm" value="1">
                    @foreach($expectedRoles as $role)<input type="hidden" name="expected_roles[]" value="{{ $role }}">@endforeach
                    <p>为 #{{ $user->id }} {{ $user->name }} 选择角色，一次保存全部变更。</p>
                    <div class="admin-ui-actions admin-operations-wide">@foreach($presets as $preset)<button type="button" class="admin-ui-button" x-on:click="selectedRoles = @js($preset['roles'])">{{ $preset['label'] }}</button>@endforeach<button type="button" class="admin-ui-button" x-on:click="selectedRoles = []">清空选择</button></div>
                    <fieldset class="admin-operations-fieldset"><legend>业务角色</legend>@foreach($roles as $role)<label title="{{ $role }}"><input type="checkbox" name="roles[]" value="{{ $role }}" x-model="selectedRoles" @checked(in_array($role, $selectedRoles, true))> {{ $roleLabels[$role] }}</label>@endforeach</fieldset>
                    <label class="admin-operations-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $restoreRoles && is_string(old('reason')) ? old('reason') : '' }}"></label>
                    <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">保存角色</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                </form>
            </x-admin.dialog>
        @endif@endforeach
    @endcan
</div>
</x-filament-panels::page>
