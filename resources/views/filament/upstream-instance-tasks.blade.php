<x-admin.card :title="$selectedInstance ? '此实例最近任务' : '最近任务'" :description="$selectedInstance ? '当前实例最近 40 条经典魔方任务，按任务 ID 倒序显示。' : '全局最近 40 条经典魔方任务，不随上方实例筛选变化。'">
    <x-admin.table :label="$selectedInstance ? '当前实例最近任务' : '全局最近任务'">
        <x-slot:head><tr><th scope="col">任务 / 账单</th><th scope="col">实例</th><th scope="col">动作</th><th scope="col">状态</th><th scope="col" class="admin-ui-number">执行次数</th><th scope="col">诊断 / 显式重试</th></tr></x-slot:head>
        @forelse($tasks as $task)
            <tr><td class="admin-ui-nowrap">#{{ $task->id }}<span class="admin-ui-meta">账单 #{{ $task->invoice_id }}</span></td><td>#{{ $task->instance_id }}</td><td>{{ $task->action }}</td>
                <td><x-admin.badge :title="$task->status->value" :tone="match($task->status) { \App\Enums\FulfillmentTaskStatus::SUCCESS => 'success', \App\Enums\FulfillmentTaskStatus::FAILED => 'danger', \App\Enums\FulfillmentTaskStatus::PENDING, \App\Enums\FulfillmentTaskStatus::RUNNING => 'warning', default => 'neutral' }">{{ $taskStatusLabels[$task->status->value] }}</x-admin.badge></td>
                <td class="admin-ui-number">{{ $task->attempts }}</td><td class="admin-ui-product-name">{{ $task->error_message }}
                @can('upstream.lifecycle')@if($task->status === \App\Enums\FulfillmentTaskStatus::FAILED)
                    @php($restoreRetry = $errors->any() && old('ui_form') === 'instance-task-retry-'.$task->id)
                    <details @if($restoreRetry) open @endif><summary>显式重试原任务</summary>
                    <form class="inventory-form" method="post" action="/admin/upstream-tasks/{{ $task->id }}/retry">@csrf
                        <input type="hidden" name="ui_form" value="instance-task-retry-{{ $task->id }}">
                        <label>重试原因<input name="reason" required value="{{ $restoreRetry && is_scalar(old('reason')) ? old('reason') : '' }}"></label><label><input type="checkbox" name="confirm" value="1" required>已对账；保留原上游意图</label><button class="inventory-button">重试原任务</button>
                    </form>
                </details>@endif@endcan
                </td>
            </tr>
        @empty<tr><td colspan="6" class="admin-ui-empty">{{ $selectedInstance ? '此实例暂无经典魔方任务。' : '暂无经典魔方任务。' }}</td></tr>@endforelse
    </x-admin.table>
</x-admin.card>
