<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
@php
    $deliveryLabels = ['pending' => '待执行', 'running' => '执行中', 'retry_wait' => '等待重试', 'uncertain' => '待核对', 'failed' => '失败', 'sent' => '已受理', 'simulated' => '模拟', 'skipped' => '跳过', 'disabled' => '停用'];
    $channelLabels = ['mail' => '邮件', 'email' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram'];
@endphp
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>已受理仅表示传输接受。待核对投递不会自动重发；请先在供应商核对原笔，不要重新创建事件。</p></div></header>
    <x-admin.card title="查询条件"><x-admin.filters action="/admin/notification-outbox"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>投递状态<select name="delivery_status"><option value="">全部</option>@foreach($deliveryLabels as $status => $label)<option value="{{ $status }}" @selected(request('delivery_status') === $status)>{{ $label }}</option>@endforeach</select></label></x-admin.filters></x-admin.card>
    <x-admin.card title="持久事件（含运营对账事件）">
        <x-admin.table label="持久通知事件"><x-slot:head><tr><th scope="col">事件 / 范围</th><th scope="col">用户</th><th scope="col">事件 / 发生键</th><th scope="col">渠道展开</th><th scope="col">记录时间</th></tr></x-slot:head>
            @forelse($events as $event)<tr><td>#{{ $event->id }}<span class="admin-ui-meta">{{ ['user' => '用户事件', 'admin' => '运营事件', 'operations' => '运营事件'][$event->scope] ?? $event->scope }}</span></td><td>{{ $event->user_id === null ? '运营事件' : '#'.$event->user_id }}</td><td class="admin-ui-product-name">{{ $event->event_type }}<span class="admin-ui-meta">{{ $event->event_key }}</span></td><td>{{ $event->expanded_at ?? '等待运营收件人' }}<span class="admin-ui-meta">{{ $event->suppression_reason }}</span></td><td>{{ $event->created_at }}</td></tr>
            @empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无匹配的持久事件</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$events" />
    </x-admin.card>
    <x-admin.card title="渠道投递" description="仅显示投递元数据，不显示收件人及消息快照。人工核对保留原事件和尝试记录。">
        <x-admin.table label="渠道投递"><x-slot:head><tr><th scope="col">投递 / 事件</th><th scope="col">用户 / 渠道</th><th scope="col">状态 / 尝试</th><th scope="col">下次执行 / 发送意图</th><th scope="col" class="admin-ui-row-actions">分类与人工核对</th></tr></x-slot:head>
            @forelse($deliveries as $delivery)<tr><td>#{{ $delivery->id }}<span class="admin-ui-meta">事件 #{{ $delivery->notification_event_id }}</span></td><td>{{ $delivery->user_id === null ? '—' : '#'.$delivery->user_id }}<span class="admin-ui-meta">{{ $channelLabels[$delivery->channel] ?? $delivery->channel }}</span></td><td><x-admin.badge :tone="in_array($delivery->status, ['uncertain', 'failed'], true) ? 'danger' : ($delivery->status === 'sent' ? 'success' : ($delivery->status === 'retry_wait' ? 'warning' : 'neutral'))">{{ $deliveryLabels[$delivery->status] ?? $delivery->status }}</x-admin.badge><span class="admin-ui-meta">尝试 {{ $delivery->attempts }} / {{ $delivery->max_attempts }}</span></td><td>{{ $delivery->available_at ?? '—' }}<span class="admin-ui-meta">发送意图 {{ $delivery->send_started_at ?? '—' }}</span></td><td class="admin-ui-row-actions"><span class="admin-ui-meta">{{ $delivery->error_code ?? '—' }}</span>
                @can('notification.manage')@if($delivery->status === 'uncertain')<button type="button" class="admin-ui-button" x-on:click="document.getElementById('outbox-resolve-{{ $delivery->id }}').showModal()">人工核对</button>@endif@endcan
            </td></tr>@empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无匹配的渠道投递</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$deliveries" />
    </x-admin.card>
    @can('notification.manage')
        @foreach($deliveries as $delivery)@if($delivery->status === 'uncertain')
            @php
                $restoreDelivery = $errors->any() && old('_admin_form') === 'outbox-resolve' && filter_var(old('record_id'), FILTER_VALIDATE_INT) === $delivery->id;
                $deliveryValue = fn ($key, $default = null) => $restoreDelivery && is_scalar(old($key, $default)) ? old($key, $default) : $default;
                $decision = in_array($deliveryValue('decision', 'accepted'), ['accepted', 'retry'], true) ? $deliveryValue('decision', 'accepted') : 'accepted';
            @endphp
            <x-admin.dialog :id="'outbox-resolve-'.$delivery->id" :title="'核对投递 #'.$delivery->id" :show-errors="$restoreDelivery" x-init="{{ $restoreDelivery ? '$nextTick(() => $el.showModal())' : '' }}">
                <form method="post" action="/admin/notification-outbox/{{ $delivery->id }}/resolve" class="inventory-form admin-operations-form">@csrf
                    <input type="hidden" name="_admin_form" value="outbox-resolve"><input type="hidden" name="record_id" value="{{ $delivery->id }}">
                    <p>已受理仅表示传输接受，不表示用户已收到。重试必须同时证明原 worker 已退出、供应商未接受且未排队。</p>
                    <label class="admin-operations-wide">结论<select name="decision"><option value="accepted" @selected($decision === 'accepted')>已核实供应商接受</option><option value="retry" @selected($decision === 'retry')>已证明未接受，重试原投递</option></select></label>
                    <label class="admin-operations-wide">供应商证据（不要填写密钥或收件地址）<textarea name="evidence" required maxlength="1000"></textarea></label>
                    @if($restoreDelivery)<p class="admin-operations-note">核验证据不会回填，请重新输入。</p>@endif
                    <label class="admin-operations-wide">原因<input name="reason" required maxlength="255" value="{{ $deliveryValue('reason') }}"></label>
                    <label class="admin-operations-wide"><input type="checkbox" name="worker_stopped" value="1" @checked((string) $deliveryValue('worker_stopped') === '1')>原 worker 已退出，无在途请求</label><label class="admin-operations-wide"><input type="checkbox" name="not_accepted" value="1" @checked((string) $deliveryValue('not_accepted') === '1')>供应商已确认原笔未接受且未排队</label><label class="admin-operations-wide"><input type="checkbox" name="confirm" value="1" required @checked((string) $deliveryValue('confirm') === '1')>确认核对结论</label>
                    <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">保存核对结果</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                </form>
            </x-admin.dialog>
        @endif@endforeach
    @endcan
</div>
</x-filament-panels::page>
