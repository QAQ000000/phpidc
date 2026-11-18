<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>全状态交付任务。待执行与运行中任务由队列和租约回收器处理，不能在此强制重置。</p><p class="inventory-note">重试只重新调度原任务。经典上游和远程发码的不明意图仍需人工对账；受理不代表交付成功。</p>
<form method="get" class="inventory-form"><label>任务 ID<input type="number" min="1" name="task_id" value="{{ request('task_id') }}"></label><label>实例 ID<input type="number" min="1" name="instance_id" value="{{ request('instance_id') }}"></label><label>账单 ID<input type="number" min="1" name="invoice_id" value="{{ request('invoice_id') }}"></label>
<label>状态<select name="status"><option value="">全部</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->value }}</option>@endforeach</select></label><button class="inventory-button">筛选</button><a class="inventory-link" href="/admin/tasks">重置</a></form></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table inventory-tasks"><thead><tr><th>任务 / 关联</th><th>动作 / 状态</th><th>次数 / 时间</th><th>人工恢复</th></tr></thead><tbody>
@forelse($tasks as $task)<tr><td>#{{ $task->id }} · 客户 #{{ $task->user_id }}<br>实例 #{{ $task->instance_id ?? '—' }} · 账单 #{{ $task->invoice_id ?? '—' }}<br>{{ $task->driver_type->value }} / 上游 {{ $task->upstream_api_id ?? '本地' }}
@can('finance.view')@if($task->invoice_id)<br><a class="inventory-link" href="/admin/finance/invoice?invoice_id={{ $task->invoice_id }}">核查账单</a>@endif@endcan
</td><td>{{ $task->action }}<br>{{ $task->status->value }}</td><td>{{ $task->attempts }} / {{ $task->max_attempts }}<br><span class="inventory-meta">可执行 {{ $task->available_at ?? '—' }}<br>租约到期 {{ $task->lease_expires_at ?? '—' }}<br>完成 {{ $task->executed_at ?? '—' }}</span></td><td>
@if($task->driver_type === \App\Enums\DriverType::ZJMF_API)
@can('upstream.view')<a class="inventory-link" href="/admin/upstream-instances">上游核查</a>@endcan
@elseif($task->upstream_api_id === null)
@can('inventory.view')<a class="inventory-link" href="/admin/fulfillment">缺货核查与补货</a>@endcan
@endif
@if($task->status === \App\Enums\FulfillmentTaskStatus::FAILED)
@php($classic = $task->driver_type === \App\Enums\DriverType::ZJMF_API)
@php($canRetry = $classic ? auth()->user()->can('upstream.view') && auth()->user()->can('upstream.lifecycle') : $task->upstream_api_id === null && in_array($task->driver_type, [\App\Enums\DriverType::REDEMPTION, \App\Enums\DriverType::SUB2API], true) && auth()->user()->can('inventory.view') && auth()->user()->can('inventory.retry'))
@if($canRetry)<form method="post" action="/admin/tasks/{{ $task->id }}/retry" class="inventory-form">@csrf<label>重试原因<input name="reason" required maxlength="255"></label><label><span><input type="checkbox" name="confirm" value="1" required> 已核查付款、库存或上游意图</span></label><button class="inventory-button">重试原任务</button></form>@else<p>需对应业务操作员人工核查。</p>@endif
@else<p class="inventory-meta">此状态不开放人工重试。</p>@endif
</td></tr>@empty<tr><td colspan="4">没有匹配的任务。</td></tr>@endforelse
</tbody></table>{{ $tasks->links() }}</div>
<section class="inventory-box inventory-scroll"><h2>失败队列记录</h2><p>通知重试耗尽也列于此处。@can('notification.manage')<a class="inventory-link" href="/admin/notifications">按用户与状态核对投递日志</a>@endcan</p><p class="inventory-note">仅显示队列元数据。不要直接 queue:retry 重放未知远程操作；先核查对应任务及上游意图。</p><table class="inventory-table"><thead><tr><th>ID / UUID</th><th>连接 / 队列</th><th>失败时间</th></tr></thead><tbody>
@forelse($failures as $failure)<tr><td>#{{ $failure->id }}<br>{{ $failure->uuid }}</td><td>{{ $failure->connection }} / {{ $failure->queue }}</td><td>{{ $failure->failed_at }}</td></tr>@empty<tr><td colspan="3">没有失败队列记录。</td></tr>@endforelse
</tbody></table>{{ $failures->links() }}</section>
</x-filament-panels::page>
