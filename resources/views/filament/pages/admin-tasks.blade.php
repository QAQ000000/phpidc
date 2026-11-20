<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
@php
    $statusLabels = ['pending' => '待执行', 'running' => '执行中', 'success' => '成功', 'failed' => '失败', 'stale' => '已失效'];
    $actionLabels = ['provision' => '开通', 'renew' => '续费', 'suspend' => '暂停', 'unsuspend' => '恢复', 'terminate' => '终止', 'upgrade' => '升级'];
    $canRetryTask = fn ($task) => $task->status === \App\Enums\FulfillmentTaskStatus::FAILED
        && ($task->driver_type === \App\Enums\DriverType::ZJMF_API
            ? auth()->user()->can('upstream.view') && auth()->user()->can('upstream.lifecycle')
            : $task->upstream_api_id === null && in_array($task->driver_type, [\App\Enums\DriverType::REDEMPTION, \App\Enums\DriverType::SUB2API], true)
                && auth()->user()->can('inventory.view') && auth()->user()->can('inventory.retry'));
@endphp
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>全状态交付任务。待执行与运行中任务由队列和租约回收器处理，不能在此强制重置。</p><p class="admin-operations-note">重试只重新调度原任务。经典上游和远程发码的不明意图仍需人工对账，受理不代表交付成功。</p></div></header>
    <x-admin.card title="交付任务">
        <x-admin.filters action="/admin/tasks">
            <label>任务 ID<input type="number" min="1" name="task_id" value="{{ request('task_id') }}"></label><label>实例 ID<input type="number" min="1" name="instance_id" value="{{ request('instance_id') }}"></label><label>账单 ID<input type="number" min="1" name="invoice_id" value="{{ request('invoice_id') }}"></label>
            <label>状态<select name="status"><option value="">全部</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $statusLabels[$status->value] ?? $status->value }}</option>@endforeach</select></label>
        </x-admin.filters>
        <x-admin.table label="交付任务"><x-slot:head><tr><th scope="col">任务 / 关联</th><th scope="col">动作 / 状态</th><th scope="col">次数 / 时间</th><th scope="col" class="admin-ui-row-actions">人工恢复</th></tr></x-slot:head>
            @forelse($tasks as $task)<tr><td class="admin-ui-product-name">任务 #{{ $task->id }}<span class="admin-ui-meta">客户 #{{ $task->user_id }} · {{ $task->instance_id ? '实例 #'.$task->instance_id : '未关联实例' }}<br>{{ $task->invoice_id ? '账单 #'.$task->invoice_id : '未关联账单' }}<br>{{ $task->driver_type->value }} · {{ $task->upstream_api_id ? '上游 #'.$task->upstream_api_id : '本地' }}</span>
                @can('finance.view')@if($task->invoice_id)<a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $task->invoice_id }}">核查账单</a>@endif
@endcan
            </td><td>{{ $actionLabels[$task->action] ?? $task->action }}<span class="admin-ui-meta"><x-admin.badge :tone="match($task->status->value) { 'success' => 'success', 'running' => 'warning', 'failed' => 'danger', default => 'neutral' }">{{ $statusLabels[$task->status->value] ?? $task->status->value }}</x-admin.badge></span></td>
                <td>{{ $task->attempts }} / {{ $task->max_attempts }}<span class="admin-ui-meta">可执行 {{ $task->available_at ?? '—' }}<br>租约到期 {{ $task->lease_expires_at ?? '—' }}<br>完成 {{ $task->executed_at ?? '—' }}</span></td>
                <td class="admin-ui-row-actions">
                    @if($task->instance_id)@can('upstream.view')<a class="admin-ui-link" href="/admin/instances/{{ $task->instance_id }}">实例工作台</a>@endcan @endif
                    @if($task->driver_type === \App\Enums\DriverType::ZJMF_API)@can('upstream.view')<a class="admin-ui-link" href="/admin/upstream-instances">上游核查</a>@endcan
                    @elseif($task->upstream_api_id === null)@can('inventory.view')<a class="admin-ui-link" href="/admin/fulfillment">缺货核查与补货</a>@endcan
@endif
                    @if($canRetryTask($task))<button type="button" class="admin-ui-button" x-on:click="document.getElementById('task-retry-{{ $task->id }}').showModal()">重试原任务</button>
                    @elseif($task->status === \App\Enums\FulfillmentTaskStatus::FAILED)<span class="admin-ui-meta">需对应业务操作员人工核查。</span>
                    @else<span class="admin-ui-meta">此状态不开放人工重试。</span>@endif
                </td>
            </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有匹配的任务</strong><p>可调整任务、实例、账单或状态筛选。</p></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tasks" />
    </x-admin.card>
    <x-admin.card title="失败队列记录" description="仅显示队列元数据；不要直接 queue:retry 重放未知远程操作，先核查对应任务及上游意图。">
        <div class="admin-ui-card-body"><p class="admin-operations-note">通知重试耗尽也列于此处。@can('notification.manage')<a class="admin-ui-link" href="/admin/notifications">按用户与状态核对投递日志</a>@endcan</p></div>
        <x-admin.table label="失败队列记录"><x-slot:head><tr><th scope="col">记录 / UUID</th><th scope="col">连接 / 队列</th><th scope="col">失败时间</th></tr></x-slot:head>
            @forelse($failures as $failure)<tr><td>#{{ $failure->id }}<span class="admin-ui-meta">{{ $failure->uuid }}</span></td><td>{{ $failure->connection }} / {{ $failure->queue }}</td><td>{{ $failure->failed_at }}</td></tr>@empty<tr><td colspan="3" class="admin-ui-empty"><strong>没有失败队列记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$failures" />
    </x-admin.card>
    @foreach($tasks as $task)@if($canRetryTask($task))
        @php($restoreRetry = $errors->any() && old('_admin_form') === 'task-retry' && (string) old('record_id') === (string) $task->id
            && (request('task_id') === null || (string) request('task_id') === (string) $task->id))
        <x-admin.dialog :id="'task-retry-'.$task->id" :title="'重试原任务 #'.$task->id" :show-errors="$restoreRetry" x-init="{{ $restoreRetry ? '$nextTick(() => $el.showModal())' : '' }}">
            <p class="admin-operations-note">仅重新调度当前失败任务，不创建账单、不重新扣款。请先核查付款、库存或原上游意图。</p>
            <form method="post" action="/admin/tasks/{{ $task->id }}/retry" class="inventory-form admin-operations-form">@csrf
                <input type="hidden" name="_admin_form" value="task-retry"><input type="hidden" name="record_id" value="{{ $task->id }}">
                <label class="admin-operations-wide">重试原因<input name="reason" required maxlength="255" value="{{ $restoreRetry ? old('reason') : '' }}"></label>
                <label class="admin-operations-wide"><input type="checkbox" name="confirm" value="1" required @checked($restoreRetry && old('confirm') === '1')>已核查付款、库存或上游意图</label>
                <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">重试原任务</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif
@endforeach
</div>
</x-filament-panels::page>
