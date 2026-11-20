<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-customers.css?v=20261120-c1">
<div class="admin-ui admin-customers-page">
    <header class="admin-ui-heading"><div><p>查询客户基本资料及已授权的业务摘要。有效服务按本地运行中、已暂停记录统计。</p></div></header>
    <x-admin.card title="客户列表">
        <x-admin.filters action="/admin/customers">
            <label>客户 ID<input name="user_id" type="number" min="1" value="{{ $filters['user_id'] }}" placeholder="精确 ID"></label>
            <label class="admin-ui-search">完整邮箱<input name="email" type="email" maxlength="255" value="{{ $filters['email'] }}" placeholder="客户完整邮箱"></label>
            <label>姓名关键词<input name="name" maxlength="100" value="{{ $filters['name'] }}" placeholder="按字面内容匹配"></label>
            <label>实名状态<select name="kyc"><option value="">全部</option><option value="verified" @selected($filters['kyc'] === 'verified')>已认证</option><option value="unverified" @selected($filters['kyc'] === 'unverified')>未认证</option></select></label>
            <label>账户状态<select name="status"><option value="active" @selected($filters['status'] === 'active')>正常</option><option value="closed" @selected($filters['status'] === 'closed')>已注销</option></select></label>
        </x-admin.filters>
        <x-admin.table label="客户列表"><x-slot:head><tr><th scope="col">客户 ID</th><th scope="col">姓名 / 邮箱</th>@if($permissions['finance'])<th scope="col" class="admin-ui-number">可用余额 / 账单冻结 / 结清冻结</th>@endif @if($permissions['services'])<th scope="col" class="admin-ui-number">本地有效服务</th>@endif<th scope="col">实名 / 账户</th><th scope="col">注册时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($customers as $customer)
                <tr data-customer-id="{{ $customer->id }}">
                    <td>#{{ $customer->id }}</td><td class="admin-customers-name"><a class="admin-ui-link" href="/admin/customers/{{ $customer->id }}">{{ $customer->name }}</a><span class="admin-ui-meta">{{ $customer->email }}</span></td>
                    @if($permissions['finance'])<td class="admin-ui-number admin-customers-wallet" data-customer-wallet="{{ $customer->id }}">{{ $customer->credit }} / {{ $customer->frozen_credit }} / {{ $customer->wallet_clearance_frozen }}<span class="admin-ui-meta">CNY · 三列分别展示</span></td>@endif
                    @if($permissions['services'])<td class="admin-ui-number" data-customer-services="{{ $customer->id }}">{{ $customer->active_services_count }}</td>@endif
                    <td><x-admin.badge :tone="$customer->kyc_verified_at ? 'success' : 'neutral'">{{ $customer->kyc_verified_at ? '已认证' : '未认证' }}</x-admin.badge><span class="admin-ui-meta"><x-admin.badge :tone="$customer->trashed() || $customer->anonymized_at !== null ? 'neutral' : 'success'">{{ $customer->trashed() || $customer->anonymized_at !== null ? '已注销' : '正常' }}</x-admin.badge></span></td>
                    <td class="admin-ui-nowrap">{{ $customer->created_at?->format('Y-m-d H:i') }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/customers/{{ $customer->id }}">查看详情</a></td>
                </tr>
            @empty<tr><td colspan="{{ 5 + (int) $permissions['finance'] + (int) $permissions['services'] }}" class="admin-ui-empty"><strong>没有匹配的客户</strong>调整筛选条件后重试。</td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$customers" />
    </x-admin.card>
</div>
</x-filament-panels::page>
