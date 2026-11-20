<x-filament-panels::page>
@include('filament.admin-ui-styles')
@php($workspaceOrigin = \App\Support\AdminWorkspaceNavigation::origin(auth()->user(), 'instances', null, request()->query()))
<div class="admin-ui">
    @include('filament.inventory-feedback')
    <header class="admin-ui-heading"><div><h2>{{ $selectedInstance ? '上游实例详情' : '经典魔方服务' }}</h2><p>查看已保存的服务状态、续费信息与最近任务。</p></div></header>
    @if($selectedInstance)
        @include('filament.upstream-instance-detail')
    @else
        <x-admin.card title="实例列表" description="状态与到期时间为本地已保存记录，进入详情后可核对机器命令与执行授权操作。">
            <x-admin.filters action="/admin/upstream-instances">
                <label class="admin-ui-search" for="instance-search">关键词<input id="instance-search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="服务名称 / 本地 ID / 上游主机 ID"></label>
                <label for="instance-customer">客户 ID<input id="instance-customer" name="user_id" type="number" min="1" value="{{ $filters['user_id'] ?? '' }}"></label>
                <label for="instance-account">上游连接<select id="instance-account" name="account_id"><option value="">全部连接</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($filters['account_id'] === (string) $account->id)>{{ $account->name }}{{ $account->is_active ? '' : '（停用）' }}</option>@endforeach</select></label>
                <label for="instance-status">服务状态<select id="instance-status" name="status"><option value="">全部状态</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $statusLabels[$status->value] }}</option>@endforeach</select></label>
            </x-admin.filters>
            <x-admin.table label="经典魔方实例列表">
                <x-slot:head><tr><th scope="col">服务</th><th scope="col">上游连接 / 主机</th><th scope="col">本地状态</th><th scope="col">到期时间</th><th scope="col" class="admin-ui-number">续费金额</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($instances as $instance)
                    @php($connection = $accounts->firstWhere('id', $instance->upstream_api_id))
                    @php($detailUrl = '/admin/upstream-instances?'.http_build_query([...array_filter([...$filters, 'page' => request()->query('page')], fn ($value) => $value !== null && $value !== ''), 'instance' => $instance->id]))
                    <tr>
                        <td class="admin-ui-product-name"><a class="admin-ui-link" href="{{ $detailUrl }}">{{ $instance->display_name }}</a><span class="admin-ui-meta">#{{ $instance->id }}</span></td>
                        <td>{{ $connection?->name ?? '未关联连接' }}<span class="admin-ui-meta">#{{ $instance->upstream_api_id }} / {{ $instance->upstream_instance_id ?? '未绑定主机' }}@if($connection && !$connection->is_active) · 连接停用@endif</span></td>
                        <td><x-admin.badge :title="$instance->status->value" :tone="match($instance->status) { \App\Enums\InstanceStatus::ACTIVE => 'success', \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning', \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger', default => 'neutral' }">{{ $statusLabels[$instance->status->value] }}</x-admin.badge></td>
                        <td class="admin-ui-nowrap">{{ $instance->next_due_at?->format('Y-m-d H:i') ?? '未设置' }}</td>
                        <td class="admin-ui-number"><span class="admin-ui-nowrap">{{ $instance->recurring_amount }} <small>{{ $instance->currency }}</small></span><span class="admin-ui-meta">{{ $instance->billing_cycle->label() }}</span></td>
                        <td class="admin-ui-row-actions"><div><a class="admin-ui-link" href="{{ $detailUrl }}">实例详情</a><a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, $workspaceOrigin) }}">实例工作台</a><a class="admin-ui-link" href="/admin/machine-operation-review?instance_id={{ $instance->id }}">命令核对</a></div></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="admin-ui-empty"><strong>{{ $hasFilters ? '没有匹配实例' : '暂无经典魔方服务' }}</strong><p>{{ $hasFilters ? '调整筛选条件或重置后重试。' : '经典魔方服务建立后会显示在这里。' }}</p>@if($hasFilters)<a class="admin-ui-button" href="/admin/upstream-instances">重置筛选</a>@endif</td></tr>
                @endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$instances" />
        </x-admin.card>
    @endif
    @include('filament.upstream-instance-tasks')
</div>
</x-filament-panels::page>
