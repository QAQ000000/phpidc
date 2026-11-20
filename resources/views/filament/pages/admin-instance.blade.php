<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-instance.css?v=20261120-c4">
@php
    $restoreInstanceForm = $errors->any() && is_scalar(old('instance_id')) && (string) old('instance_id') === (string) $instance->id;
    $restoreSync = $restoreInstanceForm && old('_admin_form') === 'instance-sync' && $canSync;
    $serviceTone = match($instance->status) {
        \App\Enums\InstanceStatus::ACTIVE => 'success',
        \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning',
        \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger',
        default => 'neutral',
    };
@endphp
<div class="admin-ui admin-instance-workbench" x-data>
    @if(session('instance_status'))<p role="status" class="inventory-alert">{{ session('instance_status') }}</p>@endif
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading">
        <div><h2>{{ $instance->display_name }}</h2><p>实例 #{{ $instance->id }} · {{ $driverLabel }} · 信息以最近保存记录为准。</p></div>
        <div class="admin-ui-actions"><a class="admin-ui-button" href="/admin/upstream-instances">返回经典服务列表</a>@if($classic)<a class="admin-ui-button" href="/admin/upstream-instances?instance={{ $instance->id }}">经典简要详情</a>@endif @if($permissions['customers'])<a class="admin-ui-button" href="/admin/customers/{{ $instance->user_id }}#customer-services">客户服务记录</a>@endif</div>
    </header>
    <x-admin.card id="instance-summary" title="服务概况">
        <div class="admin-ui-card-body"><dl class="admin-ui-facts">
            <div><dt>服务状态</dt><dd><x-admin.badge :tone="$serviceTone" :title="$instance->status->value">{{ $instanceLabels[$instance->status->value] }}</x-admin.badge><span class="admin-ui-meta">{{ $instance->status->value }}</span></dd></div>
            <div><dt>资源类型</dt><dd>{{ $driverLabel }}<span class="admin-ui-meta">{{ $instance->driver_type->value }}</span></dd></div>
            <div><dt>主机名</dt><dd>{{ $instance->hostname ?? '未设置' }}</dd></div>
            <div><dt>主 IP</dt><dd>{{ $instance->primary_ip ?? '未分配' }}</dd></div>
            <div><dt>其他 IP</dt><dd>{{ $assignedIps === [] ? '未记录' : implode('、', $assignedIps) }}</dd></div>
            <div><dt>最近同步</dt><dd>{{ $instance->last_synced_at?->format('Y-m-d H:i') ?? '尚未同步' }}<span class="admin-ui-meta">此时间不代表当前远端可达。</span></dd></div>
            <div><dt>创建时间</dt><dd>{{ $instance->created_at?->format('Y-m-d H:i') ?? '未记录' }}</dd></div>
            <div><dt>本地更新时间</dt><dd>{{ $instance->updated_at?->format('Y-m-d H:i') ?? '未记录' }}</dd></div>
        </dl></div>
    </x-admin.card>
    <x-admin.card id="instance-ownership" title="归属与商品">
        <div class="admin-ui-card-body"><dl class="admin-ui-facts">
            <div><dt>原客户</dt><dd>@if($permissions['customers'])<a class="admin-ui-link" href="/admin/customers/{{ $instance->user_id }}">客户 #{{ $instance->user_id }}{{ $owner ? ' · '.$owner->name : '' }}</a>@else 客户 #{{ $instance->user_id }}@endif</dd></div>
            @if($owner)<div><dt>客户账户</dt><dd>{{ $owner->deleted_at !== null || $owner->anonymized_at !== null ? '已注销' : ($owner->disabled_at !== null ? '已停用' : '正常') }}</dd></div>@endif
            <div><dt>商品</dt><dd>{{ $product?->name ?? '历史商品' }}<span class="admin-ui-meta">商品 #{{ $instance->product_id }}</span></dd></div>
            <div><dt>连接</dt><dd>{{ $connection?->name ?? ($instance->upstream_api_id === null ? '本地资源' : '连接已不可用') }}@if($connection)<span class="admin-ui-meta">#{{ $connection->id }} · {{ $connection->is_active ? '启用' : '停用' }}</span>@endif</dd></div>
            <div><dt>远端资源编号</dt><dd>{{ $instance->upstream_instance_id ?? '未绑定远端资源' }}</dd></div>
        </dl></div>
    </x-admin.card>
    <x-admin.card id="instance-configuration" title="配置、计费与到期" description="展示已保存的选配及续费事实；同步上游不代表人工改价。">
        <div class="admin-ui-card-body"><dl class="admin-ui-facts">
            <div><dt>计费周期</dt><dd>{{ $instance->billing_cycle->label() }}</dd></div>
            <div><dt>续费金额</dt><dd>{{ $instance->recurring_amount }} {{ $instance->currency }}</dd></div>
            <div><dt>价格锁定</dt><dd>{{ $instance->is_price_locked ? '已锁定' : '未锁定' }}</dd></div>
            <div><dt>下次到期</dt><dd>{{ $instance->next_due_at?->format('Y-m-d H:i') ?? '未设置' }}</dd></div>
            @if($classic)<div><dt>配置映射</dt><dd>{{ $mappingReview ? '待核准' : '无待核准标记' }}</dd></div><div><dt>未来价格</dt><dd>{{ $priceReview ? '待客户确认' : '无待确认标记' }}</dd></div>@endif
            @foreach($configuration as $fact)<div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>@endforeach
        </dl>@if($configuration === [])<p class="admin-ui-meta">暂无已保存的公开选配摘要。</p>@else<p class="admin-ui-meta">选配名称取自当前公开商品配置，选择值取自服务保存记录。</p>@endif</div>
    </x-admin.card>
    <x-admin.card id="instance-operations" title="明确操作" description="远端动作由实际管理员提交，受理后仍需核对执行结果。">
        <div class="admin-ui-card-body">
            @if($classic)
                <div class="admin-instance-actions">
                    @if($canMachine)<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/machine">@csrf<input type="hidden" name="_admin_form" value="instance-machine-read"><input type="hidden" name="instance_id" value="{{ $instance->id }}"><button class="admin-ui-button admin-ui-button-primary">读取机器管理菜单</button></form>@endif
                    @if($canSync)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="instance-sync" x-on:click="document.getElementById('instance-sync').showModal()">同步上游事实</button>@endif
                    @if($canLifecycle)<a class="admin-ui-button" href="/admin/upstream-instances?instance={{ $instance->id }}#instance-lifecycle">暂停、恢复或终止</a>@endif
                    @if($canHotSwap)<a class="admin-ui-button" href="/admin/upstream-instances?instance={{ $instance->id }}#instance-hot-swap">显式换绑</a>@endif
                    @if($canMapping)<a class="admin-ui-button" href="/admin/upstream-instances/{{ $instance->id }}/mapping-review">配置映射核准</a>@endif
                    <a class="admin-ui-button" href="/admin/machine-operation-review?instance_id={{ $instance->id }}">机器命令核对与历史</a>
                </div>
                @if($machinePending)<p class="admin-instance-notice">上次请求尚无自动确认，请核对原记录；是否允许新的操作由提交时的实际核对结果决定，原请求不会自动重发。</p>@endif
                @if($cancellationPending)<p class="admin-instance-notice">已有终止申请，停止发起新的机器命令。</p>@endif
                @if(!$canMachine && !$canSync)<p class="admin-ui-meta">机器控制与同步需要对应专权、有效绑定及可操作服务状态，提交时再次核对。</p>@endif
            @else
                <p>{{ $instance->driver_type === \App\Enums\DriverType::PROXMOX ? '此驱动尚未交付后台远端控制能力，当前仅展示本地服务记录。' : '此类型当前展示本地服务记录，未开放后台远端机器控制与上游同步。' }}</p>
            @endif
            @if($permissions['tasks'])<p><a class="admin-ui-link" href="/admin/tasks?instance_id={{ $instance->id }}">前往此实例的任务监控</a></p>@endif
        </div>
    </x-admin.card>
    @if($invoices !== null)
        <x-admin.card id="instance-invoices" title="关联账单" description="当前实例及原客户的账单，按最新记录显示。">
            <x-admin.table label="实例关联账单"><x-slot:head><tr><th scope="col">账单</th><th scope="col">类型 / 状态</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">付款 / 创建时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($invoices as $invoice)<tr data-instance-invoice="{{ $invoice->id }}"><td>{{ $invoice->invoice_no }}<span class="admin-ui-meta">#{{ $invoice->id }}</span></td><td>{{ $invoiceTypes[$invoice->type->value] }}<span class="admin-ui-meta"><x-admin.badge :tone="$invoice->status === \App\Enums\InvoiceStatus::PAID ? 'success' : ($invoice->status === \App\Enums\InvoiceStatus::UNPAID ? 'warning' : 'neutral')">{{ $invoiceLabels[$invoice->status->value] }}</x-admin.badge></span></td><td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="admin-ui-nowrap">{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '尚未付款' }}<span class="admin-ui-meta">创建 {{ $invoice->created_at?->format('Y-m-d H:i') }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $invoice->id }}">账单工作台</a></td></tr>
                @empty<tr><td colspan="5" class="admin-ui-empty">此实例暂无关联账单。</td></tr>@endforelse
            </x-admin.table><x-admin.pagination :paginator="$invoices" />
        </x-admin.card>
    @endif
    @if($tasks !== null)
        <x-admin.card id="instance-tasks" title="履约任务" description="只展示当前实例及原客户的任务记录。">
            <x-admin.table label="实例履约任务"><x-slot:head><tr><th scope="col">任务 / 动作</th><th scope="col">状态</th><th scope="col">次数 / 时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($tasks as $task)<tr data-instance-task="{{ $task->id }}"><td>#{{ $task->id }} · {{ $actionLabels[$task->action] ?? $task->action }}<span class="admin-ui-meta">{{ $task->invoice_id ? '关联账单 #'.$task->invoice_id : '无关联账单' }}</span></td><td><x-admin.badge :tone="$task->status === \App\Enums\FulfillmentTaskStatus::FAILED ? 'danger' : ($task->status === \App\Enums\FulfillmentTaskStatus::SUCCESS ? 'success' : 'neutral')">{{ $taskLabels[$task->status->value] }}</x-admin.badge></td><td>{{ $task->attempts }} / {{ $task->max_attempts }}<span class="admin-ui-meta">可执行 {{ $task->available_at?->format('Y-m-d H:i') ?? '未设置' }}<br>完成 {{ $task->executed_at?->format('Y-m-d H:i') ?? '未完成' }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/tasks?task_id={{ $task->id }}">任务详情</a>@if(in_array($task->id, $retryTasks, true))<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="instance-task-retry-{{ $task->id }}" x-on:click="document.getElementById('instance-task-retry-{{ $task->id }}').showModal()">重试原任务</button>@endif</td></tr>
                @empty<tr><td colspan="4" class="admin-ui-empty">此实例暂无履约任务。</td></tr>@endforelse
            </x-admin.table><x-admin.pagination :paginator="$tasks" />
        </x-admin.card>
    @endif
    @if($tickets !== null)
        <x-admin.card id="instance-tickets" title="关联工单">
            <x-admin.table label="实例关联工单"><x-slot:head><tr><th scope="col">工单</th><th scope="col">状态</th><th scope="col">最近更新</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($tickets as $ticket)<tr data-instance-ticket="{{ $ticket->id }}"><td>#{{ $ticket->id }} · {{ $ticket->subject }}</td><td><x-admin.badge :tone="$ticket->status === 'open' ? 'warning' : 'neutral'">{{ $ticketLabels[$ticket->status] ?? $ticket->status }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $ticket->updated_at?->format('Y-m-d H:i') ?? '未记录' }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/support?user_id={{ $instance->user_id }}&amp;edit={{ $ticket->id }}">工单详情</a></td></tr>
                @empty<tr><td colspan="4" class="admin-ui-empty">此实例暂无关联工单。</td></tr>@endforelse
            </x-admin.table><x-admin.pagination :paginator="$tickets" />
        </x-admin.card>
    @endif
    @if($canSync)
        <x-admin.dialog id="instance-sync" title="同步上游事实" description="主动读取当前绑定的上游服务。状态、到期时间及交付信息可能按已核验的上游事实更新，原未决命令继续保留。" :show-errors="$restoreSync" x-init="{{ $restoreSync ? '$nextTick(() => $el.showModal())' : '' }}">
            <form method="post" action="/admin/upstream-instances/{{ $instance->id }}/sync" class="admin-instance-form">@csrf
                <input type="hidden" name="_admin_form" value="instance-sync"><input type="hidden" name="instance_id" value="{{ $instance->id }}">
                <label>备注（可选）<input name="reason" maxlength="255" value="{{ $restoreSync && is_string(old('reason')) ? old('reason') : '' }}"></label>
                <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认同步</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif
    @if($tasks !== null)@foreach($tasks as $task)@if(in_array($task->id, $retryTasks, true))
        @php($restoreRetry = $restoreInstanceForm && old('_admin_form') === 'instance-task-retry' && is_scalar(old('record_id')) && (string) old('record_id') === (string) $task->id)
        <x-admin.dialog :id="'instance-task-retry-'.$task->id" :title="'重试原任务 #'.$task->id" description="仅重新调度已付款账单的当前失败任务，不创建新订单或重复扣款；未知远端意图仍须核对。" :show-errors="$restoreRetry" x-init="{{ $restoreRetry ? '$nextTick(() => $el.showModal())' : '' }}">
            <form method="post" action="/admin/instances/{{ $instance->id }}/tasks/{{ $task->id }}/retry" class="admin-instance-form">@csrf
                <input type="hidden" name="_admin_form" value="instance-task-retry"><input type="hidden" name="instance_id" value="{{ $instance->id }}"><input type="hidden" name="record_id" value="{{ $task->id }}">
                <label>备注（可选）<input name="reason" maxlength="255" value="{{ $restoreRetry && is_string(old('reason')) ? old('reason') : '' }}"></label>
                <label><span><input type="checkbox" name="confirm" value="1" required> 已核查付款、当前绑定与原上游意图，确认重试原任务。</span></label>
                <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认重试原任务</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif @endforeach @endif
</div>
</x-filament-panels::page>
