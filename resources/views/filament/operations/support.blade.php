<header class="admin-ui-heading"><div><p>按客户和状态查找工单，打开会话查看回复并继续处理。</p></div></header>
<x-admin.card title="工单列表">
    <x-admin.filters action="/admin/support"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>状态<select name="status"><option value="">全部</option>@foreach(['open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭'] as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select></label></x-admin.filters>
    <x-admin.table label="工单列表"><x-slot:head><tr><th scope="col">工单 / 客户</th><th scope="col">状态</th><th scope="col">创建时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr><td class="admin-ui-product-name">{{ $record->subject }}<span class="admin-ui-meta">工单 #{{ $record->id }} · 客户 #{{ $record->user_id }}</span></td><td><x-admin.badge :tone="$record->status === 'open' ? 'warning' : ($record->status === 'answered' ? 'success' : 'neutral')">{{ match($record->status) { 'open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭', default => $record->status } }}</x-admin.badge></td><td class="admin-ui-nowrap">{{ $record->created_at?->format('Y-m-d H:i') }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['edit' => $record->id, 'replies_page' => null]) }}">查看会话</a></td></tr>
        @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无匹配工单</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
@if($ticket)
    <x-admin.card :title="'#'.$ticket->id.' '.$ticket->subject" :description="'客户 #'.$ticket->user_id.' · '.($ticket->instance_id === null ? '未关联服务' : '服务 #'.$ticket->instance_id).' · '.match($ticket->status) { 'open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭', default => $ticket->status }">
        <x-slot:actions>@can('support.reply')<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('ticket-action').showModal()">{{ $ticket->status === 'closed' ? '重开工单' : '处理工单' }}</button>@endcan<a class="admin-ui-button" href="/admin/support">返回工单列表</a></x-slot:actions>
        <div class="admin-ui-card-body admin-operations-thread">
            @forelse($replies as $reply)<article class="admin-operations-message"><p class="admin-operations-note">{{ $reply->is_admin ? '客服' : '客户' }} #{{ $reply->user_id }} · {{ $reply->created_at }}</p><div class="admin-operations-body" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $reply->body }}</div></article>@empty<p class="admin-operations-note">暂无回复。</p>@endforelse
        </div><x-admin.pagination :paginator="$replies" />
    </x-admin.card>
    @can('support.reply')
        @php($restoreTicket = $restoreContext === 'ticket' && (int) old('record_id') === $ticket->id)
        <x-admin.dialog id="ticket-action" :title="$ticket->status === 'closed' ? '重开工单 #'.$ticket->id : '处理工单 #'.$ticket->id" :show-errors="$restoreTicket" x-init="{{ $restoreTicket ? '$nextTick(() => $el.showModal())' : '' }}">
            <p>{{ $ticket->subject }}</p>
            <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/tickets/{{ $ticket->id }}">@csrf<input type="hidden" name="_admin_form" value="ticket"><input type="hidden" name="record_id" value="{{ $ticket->id }}">
                <label class="admin-operations-wide">正文<textarea name="body" maxlength="20000" rows="6">{{ $restoreTicket ? old('body') : '' }}</textarea></label>
                <label class="admin-operations-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $restoreTicket ? old('reason') : '' }}"></label>
                <div class="admin-operations-form-actions admin-operations-wide">
                    @if($ticket->status === 'closed')<button class="admin-ui-button admin-ui-button-primary" name="operation" value="reopen">重开工单</button>@else<button class="admin-ui-button admin-ui-button-primary" name="operation" value="reply">客服回复</button><button class="admin-ui-button" name="operation" value="close">关闭工单</button>@endif
                    <button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button>
                </div>
            </form>
        </x-admin.dialog>
    @endcan
@endif
