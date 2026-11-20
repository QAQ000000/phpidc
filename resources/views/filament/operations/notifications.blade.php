@php
    $restoreTemplate = $restoreContext === 'notification-template' && (string) old('id', '') === (string) ($editing?->id ?? '');
    $templateValue = fn ($key, $default = null) => $restoreTemplate ? old($key, $default) : $default;
    $openTemplate = $errors->any() ? $restoreTemplate : ($editing !== null || request()->boolean('create'));
@endphp
<header class="admin-ui-heading"><div><p><a class="admin-ui-link" href="/admin/notification-outbox">查看通知记录和待核对结果</a>。正文可使用双花括号变量：{{ implode('、', $variables) }}。短信需要已审核模板及可用供应商。</p></div>
    @can('notification.manage')<div class="admin-ui-actions">@if($editing)<a class="admin-ui-button admin-ui-button-primary" href="/admin/notifications?create=1">创建通知模板</a>@else<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('notification-template-editor').showModal()">创建通知模板</button>@endif</div>@endcan
</header>
<x-admin.card title="通知模板">
    <x-admin.table label="通知模板"><x-slot:head><tr><th scope="col">事件 / 模板</th><th scope="col">渠道</th><th scope="col">状态</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr><td class="admin-ui-product-name">{{ $record->event_key }}<span class="admin-ui-meta">模板 #{{ $record->id }}</span></td><td>{{ match($record->channel) { 'email' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram', default => $record->channel } }}</td><td><x-admin.badge :tone="$record->is_enabled ? 'success' : 'neutral'">{{ $record->is_enabled ? '启用' : '停用' }}</x-admin.badge></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['edit' => $record->id, 'create' => null]) }}">{{ auth()->user()->can('notification.manage') ? '查看 / 编辑' : '查看' }}</a></td></tr>
        @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无通知模板</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
<x-admin.card title="投递日志" description="不显示收件人和异常原文。临时拒绝自动退避，最多尝试三次；结果不确定须先核对供应商，禁止直接重放。最终耗尽请在任务监控查看失败队列。">
    <x-admin.filters action="/admin/notifications"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>投递状态<select name="delivery_status"><option value="">全部</option>@foreach(['failed' => '失败', 'simulated' => '模拟', 'sent' => '已受理', 'skipped' => '跳过', 'disabled' => '停用'] as $state => $label)<option value="{{ $state }}" @selected(request('delivery_status') === $state)>{{ $label }}</option>@endforeach</select></label></x-admin.filters>
    <x-admin.table label="投递日志"><x-slot:head><tr><th scope="col">日志 / 用户</th><th scope="col">事件 / 渠道</th><th scope="col">状态 / 时间</th><th scope="col">投递说明</th></tr></x-slot:head>
        @forelse($logs as $log)<tr><td>#{{ $log->id }}<span class="admin-ui-meta">用户 #{{ $log->user_id }}</span></td><td>{{ $log->event_key }}<span class="admin-ui-meta">{{ $log->channel }}</span></td><td><x-admin.badge :tone="$log->status === 'failed' ? 'danger' : ($log->status === 'sent' ? 'success' : 'neutral')">{{ $log->status }}</x-admin.badge><span class="admin-ui-meta">{{ $log->created_at }}</span></td><td class="admin-ui-product-name">{{ $log->delivery_note }}</td></tr>
        @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无匹配投递日志</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$logs" />
</x-admin.card>
@can('notification.manage')
    @php
        if ($restoreTemplate && session()->hasOldInput('mapping_rows_present')) {
            // No rows with the presence marker means the operator removed every mapping.
            $mappingRows = old('mapping_rows', []);
            $mappingRows = is_array($mappingRows) ? $mappingRows : [];
        } else {
            $mappingRows = [];
            foreach ($editing?->variable_mapping ?? [] as $externalName => $variable) {
                $mappingRows[] = ['name' => (string) $externalName, 'value' => $variable];
            }
        }
        $mappingRows = $mappingRows ?: [['name' => '', 'value' => '']];
    @endphp
    <x-admin.dialog id="notification-template-editor" :title="$editing ? '编辑通知模板 #'.$editing->id : '创建通知模板'" :show-errors="$restoreTemplate" x-init="{{ $openTemplate ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/config/template" x-data="{ channel: @js($templateValue('channel', $editing?->channel ?? 'email')), rows: @js($mappingRows) }">@csrf
            <input type="hidden" name="_admin_form" value="notification-template"><input type="hidden" name="id" value="{{ $editing?->id }}">
            <label>事件<select name="event_key">@foreach($events as $event)<option @selected($templateValue('event_key', $editing?->event_key) === $event)>{{ $event }}</option>@endforeach</select></label>
            <label>渠道<select name="channel" x-model="channel">@foreach(['email' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram'] as $channel => $channelLabel)<option value="{{ $channel }}" @selected($templateValue('channel', $editing?->channel ?? 'email') === $channel)>{{ $channelLabel }}</option>@endforeach</select></label>
            <label class="admin-operations-wide">主题<input name="subject" value="{{ $templateValue('subject', $editing?->subject) }}" maxlength="255"></label>
            <label class="admin-operations-wide">正文<textarea name="body_markdown" rows="6" required>{{ $templateValue('body_markdown', $editing?->body_markdown) }}</textarea></label>
            <fieldset class="admin-operations-fieldset admin-operations-wide" x-show="channel === 'sms'" x-bind:disabled="channel !== 'sms'"><legend>短信模板</legend>
                <label>已审核短信模板 ID<input name="external_template_id" value="{{ $templateValue('external_template_id', $editing?->external_template_id) }}"></label><input type="hidden" name="mapping_rows_present" value="1">
                <template x-for="(row, index) in rows" :key="index"><div class="inventory-form">
                    <label>供应商变量名<input :name="'mapping_rows[' + index + '][name]'" x-model="row.name" maxlength="255" placeholder="例如：amount"></label>
                    <label>使用的内容<select :name="'mapping_rows[' + index + '][value]'" x-model="row.value"><option value="">选择变量</option>@foreach($variables as $variable)<option value="{{ $variable }}">{{ $variable }}</option>@endforeach</select></label>
                    <button type="button" class="admin-ui-button" @click="rows.splice(index, 1)">移除变量</button></div></template>
                <button type="button" class="admin-ui-button" @click="rows.push({ name: '', value: '' })">添加变量映射</button>
            </fieldset>
            <label>状态<select name="is_enabled"><option value="1" @selected((string) $templateValue('is_enabled', $editing?->is_enabled ?? true) === '1')>启用</option><option value="0" @selected((string) $templateValue('is_enabled', $editing?->is_enabled ?? true) !== '1')>停用</option></select></label>
            <label>备注（可选）<input name="reason" maxlength="255" value="{{ $templateValue('reason') }}"></label>
            <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">保存模板</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@else
    @if($editing)<x-admin.card :title="'通知模板 #'.$editing->id" description="当前为只读查看。"><div class="admin-ui-card-body"><p>{{ $editing->subject }}</p><div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $editing->body_markdown }}</div></div></x-admin.card>@endif
@endcan
