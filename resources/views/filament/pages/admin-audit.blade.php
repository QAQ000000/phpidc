<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><p>审计只读。前后快照仅展示允许的状态、金额、角色及关联 ID；未列出的字段不会原样显示。</p>
<form method="get" class="inventory-form"><label>操作者 ID<input name="actor_id" type="number" min="1" value="{{ request('actor_id') }}"></label><label>完整动作名<input name="action" maxlength="64" value="{{ request('action') }}" placeholder="invoice.refund"></label><label>对象 ID<input name="subject_id" type="number" min="1" value="{{ request('subject_id') }}"></label><button class="inventory-button">筛选</button><a class="inventory-link" href="/admin/audit">重置</a></form></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table inventory-tasks"><thead><tr><th>审计 / 操作者</th><th>动作 / 对象</th><th>原因 / 请求</th><th>前后快照</th></tr></thead><tbody>
@forelse($records as $record)<tr><td>#{{ $record->id }}<br>{{ $record->created_at }}<br>{{ $record->actor_type }} #{{ $record->actor_id ?? '—' }}</td><td>{{ $record->action }}<br>{{ $record->subject_type }} #{{ $record->subject_id ?? '—' }}</td><td>{{ $record->reason ?? '—' }}<br><span class="inventory-meta">IP {{ $record->ip ?? '—' }}<br>请求 {{ $record->request_id ?? '—' }}</span></td><td><div class="inventory-meta">变更前：{{ $record->before }}<br>变更后：{{ $record->after }}</div></td></tr>@empty<tr><td colspan="4">没有匹配的审计记录。</td></tr>@endforelse
</tbody></table>{{ $records->links() }}</div>
</x-filament-panels::page>
