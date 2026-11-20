<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
@php($statusLabels = ['pending' => '待执行', 'running' => '执行中', 'failed' => '失败，需人工处理'])
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>仅显示已付款、尚未交付且绑定有效的本地卡池任务。库存为 0 是缺货候选，其他失败需核查原因。</p><p class="admin-operations-note">待执行 / 执行中任务由队列和调度器恢复，请勿重置租约。</p></div></header>
    <x-admin.card title="已付待交付">
        <x-admin.filters action="/admin/fulfillment">
            @if(request('product_id'))<input type="hidden" name="product_id" value="{{ request('product_id') }}">@endif
            <label>搜索商品<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="商品名称"></label>
        </x-admin.filters>
        <x-admin.table label="已付待交付任务"><x-slot:head><tr><th scope="col">账单 / 商品</th><th scope="col">任务状态</th><th scope="col">重试 / 原因</th><th scope="col" class="admin-ui-row-actions">人工处理</th></tr></x-slot:head>
            @forelse($tasks as $task)<tr><td class="admin-ui-product-name">{{ $task->product_name }}<span class="admin-ui-meta">{{ $task->invoice_no }}<br>实例 #{{ $task->instance_id }} · 任务 #{{ $task->id }}</span></td>
                <td><x-admin.badge :tone="match($task->status) { 'failed' => 'danger', 'running' => 'warning', default => 'neutral' }">{{ $statusLabels[$task->status] ?? $task->status }}</x-admin.badge><span class="admin-ui-meta">尝试 {{ $task->attempts }}/{{ $task->max_attempts }} · 可用 {{ $task->available_count }}</span>@if($task->available_count === 0)<x-admin.badge tone="warning">缺货候选</x-admin.badge>@endif</td>
                <td>{{ Illuminate\Support\Str::limit($task->error_message ?? '—', 200) }}<span class="admin-ui-meta">可执行 {{ $task->available_at ?? '—' }}</span></td>
                <td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/inventory/cards?product_id={{ $task->product_id }}">查看库存 / 补货</a>@can('inventory.retry')@if($task->status === 'failed')<button type="button" class="admin-ui-button" x-on:click="document.getElementById('fulfillment-retry-{{ $task->id }}').showModal()">确认重试</button>@endif
@endcan</td>
            </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有已付待交付任务</strong><p>可调整商品筛选，或查看全状态任务监控。</p></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tasks" />
    </x-admin.card>
    @can('inventory.retry')
        @foreach($tasks as $task)@if($task->status === 'failed')
            @php($restoreRetry = $errors->any() && old('_admin_form') === 'fulfillment-retry' && (string) old('record_id') === (string) $task->id)
            <x-admin.dialog :id="'fulfillment-retry-'.$task->id" :title="'确认重试任务 #'.$task->id" :show-errors="$restoreRetry" x-init="{{ $restoreRetry ? '$nextTick(() => $el.showModal())' : '' }}">
                <p class="admin-operations-note">{{ $task->product_name }} · {{ $task->invoice_no }}。仅恢复原任务，受理不代表交付成功。</p>
                <form method="post" action="{{ route('inventory.retry', $task->id) }}" class="inventory-form admin-operations-form">@csrf
                    <input type="hidden" name="_admin_form" value="fulfillment-retry"><input type="hidden" name="record_id" value="{{ $task->id }}">
                    <label class="admin-operations-wide">重试原因<input name="reason" required maxlength="1000" value="{{ $restoreRetry ? old('reason') : '' }}" placeholder="确认库存及失败原因"></label>
                    <label class="admin-operations-wide"><input type="checkbox" name="confirm" value="1" required @checked($restoreRetry && old('confirm') === '1')>确认恢复此任务</label>
                    <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认重试</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                </form>
            </x-admin.dialog>
        @endif
@endforeach
    @endcan
</div>
</x-filament-panels::page>
