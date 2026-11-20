<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-instance.css?v=20261120-u2b">
@php
    $workspaceQuery = ['tab' => $currentTab] + \App\Support\AdminWorkspaceNavigation::query(auth()->user(), 'instance', request()->query());
    $workspaceOrigin = fn (?string $section = null): array => \App\Support\AdminWorkspaceNavigation::origin(auth()->user(), 'instance', $instance->id, $workspaceQuery, $section);
    $workspaceAction = fn (string $path): string => $path.'?'.http_build_query($workspaceQuery);
    $workspaceReturn = \App\Support\AdminWorkspaceNavigation::returnLink(auth()->user(), $workspaceQuery, '/admin/upstream-instances', '返回经典服务列表');
    $restoreInstanceForm = $errors->any() && is_scalar(old('instance_id')) && (string) old('instance_id') === (string) $instance->id;
    $restoreSync = $restoreInstanceForm && old('_admin_form') === 'instance-sync' && $canSync;
    $serviceTone = match($instance->status) {
        \App\Enums\InstanceStatus::ACTIVE => 'success',
        \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning',
        \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger',
        default => 'neutral',
    };
    $instanceTabs = ['overview' => '概览', 'configuration' => '配置与计费'];
    if ($permissions['finance']) $instanceTabs['invoices'] = '账单';
    if ($permissions['tasks']) $instanceTabs['tasks'] = '任务';
    if ($permissions['support']) $instanceTabs['tickets'] = '工单';
@endphp
<div class="admin-ui admin-instance-workbench" x-data>
    @if(session('instance_status'))<p role="status" class="inventory-alert">{{ session('instance_status') }}</p>@endif
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <x-admin.workspace-header :name="$instance->display_name" :identity="'实例 #'.$instance->id.' · '.$driverLabel" :status="$instanceLabels[$instance->status->value]" :tone="$serviceTone" :return-url="$workspaceReturn['url']" :return-label="$workspaceReturn['label']">
        @if($permissions['customers'])<a class="admin-ui-button" href="{{ \App\Support\AdminWorkspaceNavigation::url('customer', $instance->user_id, $workspaceOrigin('instance-ownership')) }}">客户 #{{ $instance->user_id }}</a>@endif
    </x-admin.workspace-header>
    <nav class="admin-workspace-tabs" aria-label="实例页签">
        @foreach($instanceTabs as $tab => $label)<a class="admin-workspace-tab" data-workspace-tab="{{ $tab }}" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, ['tab' => $tab] + $workspaceQuery) }}" @if($currentTab === $tab) aria-current="page" @endif>{{ $label }}</a>@endforeach
    </nav>
    @if($machinePending)<p role="status" class="admin-instance-notice">上次机器请求尚无自动确认，请核对原记录。原请求不会自动重发。<a class="admin-ui-link" href="/admin/machine-operation-review?instance_id={{ $instance->id }}">核对命令</a></p>@endif
    @if($cancellationPending)<p role="status" class="admin-instance-notice">已有终止申请，停止发起新的机器命令。</p>@endif
    @if($mappingReview || $priceReview)<p role="status" class="admin-instance-notice">{{ $mappingReview ? '配置映射待核准。' : '' }}{{ $priceReview ? '未来价格待客户确认。' : '' }}@if($canMapping)<a class="admin-ui-link" href="/admin/upstream-instances/{{ $instance->id }}/mapping-review">查看核准记录</a>@endif</p>@endif
    @if($owner && ($owner->disabled_at !== null || $owner->deleted_at !== null || $owner->anonymized_at !== null))<p class="admin-instance-notice">归属客户{{ $owner->deleted_at !== null || $owner->anonymized_at !== null ? '已注销' : '已停用' }}。</p>@endif
    @if($currentTab === 'overview')
        <div class="admin-instance-columns">
            <div class="admin-instance-main">
                <x-admin.card id="instance-summary" title="服务概况">
                    <div class="admin-ui-card-body"><dl class="admin-ui-facts admin-instance-core">
                        <div><dt>主机名</dt><dd>{{ $instance->hostname ?? '未设置' }}</dd></div>
                        <div><dt>主 IP</dt><dd>{{ $instance->primary_ip ?? '未分配' }}</dd></div>
                        <div><dt>下次到期</dt><dd>{{ $instance->next_due_at?->format('Y-m-d H:i') ?? '未设置' }}</dd></div>
                        <div><dt>续费金额</dt><dd>{{ $instance->recurring_amount }} {{ $instance->currency }}<span class="admin-ui-meta">{{ $instance->billing_cycle->label() }}{{ $instance->is_price_locked ? ' · 已锁价' : '' }}</span></dd></div>
                    </dl></div>
                </x-admin.card>
            </div>
            <aside class="admin-instance-rail" aria-label="实例操作">
                @include('filament.instance-workbench-actions')
            </aside>
            <div class="admin-instance-details" id="instance-ownership">
                <x-admin.card title="资源与归属">
                    <div class="admin-ui-card-body"><dl class="admin-ui-facts">
                        <div><dt>客户</dt><dd>@if($permissions['customers'])<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('customer', $instance->user_id, $workspaceOrigin('instance-ownership')) }}">客户 #{{ $instance->user_id }}{{ $owner ? ' · '.$owner->name : '' }}</a>@else 客户 #{{ $instance->user_id }}@endif</dd></div>
                        <div><dt>商品</dt><dd>{{ $product?->name ?? '历史商品' }}<span class="admin-ui-meta">商品 #{{ $instance->product_id }}</span></dd></div>
                        <div><dt>上游连接</dt><dd>{{ $connection?->name ?? ($instance->upstream_api_id === null ? '本地资源' : '连接已不可用') }}@if($connection)<span class="admin-ui-meta">#{{ $connection->id }} · {{ $connection->is_active ? '启用' : '停用' }}</span>@endif</dd></div>
                        <div><dt>远端资源编号</dt><dd>{{ $instance->upstream_instance_id ?? '未绑定远端资源' }}</dd></div>
                        <div><dt>其他 IP</dt><dd>{{ $assignedIps === [] ? '未记录' : implode('、', $assignedIps) }}</dd></div>
                        <div><dt>最近同步</dt><dd>{{ $instance->last_synced_at?->format('Y-m-d H:i') ?? '尚未同步' }}</dd></div>
                        @if($instance->suspend_reason)<div><dt>暂停原因</dt><dd>{{ $instance->suspend_reason }}</dd></div>@endif
                    </dl></div>
                </x-admin.card>
            </div>
        </div>
        @include('filament.instance-workbench-dialogs')
    @elseif($currentTab === 'configuration')
        <x-admin.card id="instance-configuration" title="配置与计费">
            <div class="admin-ui-card-body"><dl class="admin-ui-facts">
                <div><dt>计费周期</dt><dd>{{ $instance->billing_cycle->label() }}</dd></div>
                <div><dt>续费金额</dt><dd>{{ $instance->recurring_amount }} {{ $instance->currency }}</dd></div>
                <div><dt>价格锁定</dt><dd>{{ $instance->is_price_locked ? '已锁定' : '未锁定' }}</dd></div>
                <div><dt>下次到期</dt><dd>{{ $instance->next_due_at?->format('Y-m-d H:i') ?? '未设置' }}</dd></div>
                <div><dt>创建时间</dt><dd>{{ $instance->created_at?->format('Y-m-d H:i') ?? '未记录' }}</dd></div>
                <div><dt>本地更新时间</dt><dd>{{ $instance->updated_at?->format('Y-m-d H:i') ?? '未记录' }}</dd></div>
                @foreach($configuration as $fact)<div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>@endforeach
            </dl>@if($configuration === [])<p class="admin-ui-meta">暂无已保存的公开选配摘要。</p>@endif</div>
        </x-admin.card>
    @endif
    @if($invoices !== null)
        <x-admin.card id="instance-invoices" title="关联账单" description="当前实例及原客户的账单，按最新记录显示。">
            <x-admin.table label="实例关联账单"><x-slot:head><tr><th scope="col">账单</th><th scope="col">类型 / 状态</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">付款 / 创建时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($invoices as $invoice)<tr data-instance-invoice="{{ $invoice->id }}"><td>{{ $invoice->invoice_no }}<span class="admin-ui-meta">#{{ $invoice->id }}</span></td><td>{{ $invoiceTypes[$invoice->type->value] }}<span class="admin-ui-meta"><x-admin.badge :tone="$invoice->status === \App\Enums\InvoiceStatus::PAID ? 'success' : ($invoice->status === \App\Enums\InvoiceStatus::UNPAID ? 'warning' : 'neutral')">{{ $invoiceLabels[$invoice->status->value] }}</x-admin.badge></span></td><td class="admin-ui-number admin-ui-nowrap">{{ $invoice->total_amount }} {{ $invoice->currency }}</td><td class="admin-ui-nowrap">{{ $invoice->paid_at?->format('Y-m-d H:i') ?? '尚未付款' }}<span class="admin-ui-meta">创建 {{ $invoice->created_at?->format('Y-m-d H:i') }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('invoice', $invoice->id, $workspaceOrigin('instance-invoices')) }}">账单工作台</a></td></tr>
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
    @if($canSync && $currentTab === 'overview')
        <x-admin.dialog id="instance-sync" title="同步上游事实" description="主动读取当前绑定的上游服务。状态、到期时间及交付信息可能按已核验的上游事实更新，原未决命令继续保留。" :show-errors="$restoreSync" x-init="{{ $restoreSync ? '$nextTick(() => $el.showModal())' : '' }}">
            <form method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/sync') }}" class="admin-instance-form">@csrf
                <input type="hidden" name="_admin_form" value="instance-sync"><input type="hidden" name="instance_id" value="{{ $instance->id }}">
                <label>备注（可选）<input name="reason" maxlength="255" value="{{ $restoreSync && is_string(old('reason')) ? old('reason') : '' }}"></label>
                <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认同步</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif
    @if($tasks !== null)@foreach($tasks as $task)@if(in_array($task->id, $retryTasks, true))
        @php($restoreRetry = $restoreInstanceForm && old('_admin_form') === 'instance-task-retry' && is_scalar(old('record_id')) && (string) old('record_id') === (string) $task->id)
        <x-admin.dialog :id="'instance-task-retry-'.$task->id" :title="'重试原任务 #'.$task->id" description="仅重新调度已付款账单的当前失败任务，不创建新订单或重复扣款；未知远端意图仍须核对。" :show-errors="$restoreRetry" x-init="{{ $restoreRetry ? '$nextTick(() => $el.showModal())' : '' }}">
            <form method="post" action="{{ $workspaceAction('/admin/instances/'.$instance->id.'/tasks/'.$task->id.'/retry') }}" class="admin-instance-form">@csrf
                <input type="hidden" name="_admin_form" value="instance-task-retry"><input type="hidden" name="instance_id" value="{{ $instance->id }}"><input type="hidden" name="record_id" value="{{ $task->id }}">
                <label>备注（可选）<input name="reason" maxlength="255" value="{{ $restoreRetry && is_string(old('reason')) ? old('reason') : '' }}"></label>
                <label><span><input type="checkbox" name="confirm" value="1" required> 已核查付款、当前绑定与原上游意图，确认重试原任务。</span></label>
                <div class="admin-instance-form-actions"><button class="admin-ui-button admin-ui-button-primary">确认重试原任务</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif @endforeach @endif
</div>
</x-filament-panels::page>
