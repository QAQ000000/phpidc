<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
<div class="admin-ui admin-operations-page">
    <header class="admin-ui-heading"><div><p>审计只读。前后快照仅展示允许的状态、金额、角色及关联 ID；未列出的字段不会原样显示。</p></div></header>
    <x-admin.card title="操作审计">
        <x-admin.filters action="/admin/audit"><label>操作者 ID<input name="actor_id" type="number" min="1" value="{{ $filters['actor_id'] }}"></label><label>完整动作名<input name="action" maxlength="64" value="{{ $filters['action'] }}" placeholder="invoice.refund"></label><label>对象类型<select name="subject_type"><option value="">全部对象</option>@foreach($subjectTypes as $type => $label)<option value="{{ $type }}" @selected($filters['subject_type'] === $type)>{{ $label }}</option>@endforeach</select></label><label>对象 ID<input name="subject_id" type="number" min="1" value="{{ $filters['subject_id'] }}"></label></x-admin.filters>
        <x-admin.table label="操作审计"><x-slot:head><tr><th scope="col">审计 / 操作者</th><th scope="col">动作 / 对象</th><th scope="col">原因 / 请求</th><th scope="col">前后快照</th></tr></x-slot:head>
            @forelse($records as $record)<tr><td>#{{ $record->id }}<span class="admin-ui-meta">{{ $record->created_at }}<br>{{ $record->actor_type === 'system' ? '系统' : $record->actor_type }} {{ $record->actor_id === null ? '—' : '#'.$record->actor_id }}</span></td><td class="admin-ui-product-name">{{ $record->action }}<span class="admin-ui-meta">{{ $record->subject_type }} {{ $record->subject_id === null ? '—' : '#'.$record->subject_id }}</span></td><td class="admin-ui-product-name">{{ $record->reason ?? '—' }}<span class="admin-ui-meta">IP {{ $record->ip ?? '—' }}<br>请求 {{ $record->request_id ?? '—' }}</span></td><td><details><summary>查看允许的变更字段</summary><div class="admin-operations-note">变更前：{{ $record->before }}<br>变更后：{{ $record->after }}</div></details></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有匹配的审计记录</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$records" />
    </x-admin.card>
</div>
</x-filament-panels::page>
