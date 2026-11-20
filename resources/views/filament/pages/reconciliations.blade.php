<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
@php
    $caseStatusLabels = ['open' => '待核对', 'awaiting_clearance' => '待清退', 'resolved' => '已结案'];
    $caseKindLabels = ['payment_attention' => '支付异常', 'binding_orphan' => '遗留资源'];
@endphp
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>原告警、回调和绑定历史保留。案件状态须由真实入账、清退或原资源处置证据决定。</p></div></header>
    <x-admin.card title="业务异常案件">
        <x-admin.table label="业务异常案件"><x-slot:head><tr><th scope="col">案件 / 原客户</th><th scope="col">类型 / 原因</th><th scope="col">状态 / 版本</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($records as $record)<tr><td>#{{ $record->id }}<span class="admin-ui-meta">原客户 #{{ $record->user_id }}</span></td><td class="admin-ui-product-name">{{ $caseKindLabels[$record->kind] ?? $record->kind }}<span class="admin-ui-meta">{{ $record->initial_reason }}</span></td><td><x-admin.badge :tone="$record->status === 'resolved' ? 'success' : 'warning'">{{ $caseStatusLabels[$record->status] ?? $record->status }}</x-admin.badge><span class="admin-ui-meta">版本 {{ $record->revision }}</span></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['case' => $record->id, 'history_page' => null]) }}">查看案件</a></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无业务异常案件</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$records" />
    </x-admin.card>
    @if($selected)
        @php
            $authority = $selected->kind === 'payment_attention' ? 'finance.reconcile' : 'upstream.orphan-reconcile';
        @endphp
        <x-admin.card :title="'案件 #'.$selected->id" :description="'原来源 '.$selected->source_type.' #'.$selected->source_id">
            <x-slot:actions><a class="admin-ui-button" href="/admin/reconciliations">返回案件列表</a>@can($authority)<form method="post" action="/admin/reconciliations/{{ $selected->id }}/evidence">@csrf<button class="admin-ui-button">查看核验证据</button></form>@endcan</x-slot:actions>
            <div class="admin-ui-card-body"><p>原客户 #{{ $selected->user_id }} · {{ $caseKindLabels[$selected->kind] ?? $selected->kind }} · {{ $caseStatusLabels[$selected->status] ?? $selected->status }} · 版本 {{ $selected->revision }}</p><p class="admin-operations-note">原始原因：{{ $selected->initial_reason }}</p>
                @can($authority)
                    @if($selected->status === 'resolved')
                        <div class="admin-ui-actions"><button type="button" class="admin-ui-button" x-on:click="document.getElementById('reconciliation-reopen-{{ $selected->id }}').showModal()">重新打开</button></div>
                    @elseif($selected->kind === 'payment_attention')
                        <p class="admin-operations-note">补记会重新计算当前账单状态和钱包容量。匿名原身份的已入账款须在<a class="admin-ui-link" href="/admin/wallet-clearances">钱包清退</a>中处理；未入账款不能直接外退。</p><div class="admin-ui-actions"><button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('reconciliation-payment-{{ $selected->id }}').showModal()">核验并补记原笔</button></div>
                    @else
                        <p class="admin-operations-note">原资源必须认证核实 Deleted 或已有完整移交及责任接受证据。此入口只登记已完成事实。</p><div class="admin-ui-actions">@foreach(['terminate_verified' => '认证核实原主机 Deleted', 'transfer_verified' => '登记已完成移交'] as $operation => $label)<button type="button" class="admin-ui-button" x-on:click="document.getElementById('reconciliation-{{ $operation }}-{{ $selected->id }}').showModal()">{{ $label }}</button>@endforeach</div>
                    @endif
                @endcan
            </div>
        </x-admin.card>
        @if($entries)
            <x-admin.card title="追加处置历史" description="保留原始处置事实；核验证据仅通过受审计的查看入口读取。">
                <x-admin.table label="案件追加处置历史"><x-slot:head><tr><th scope="col">版本 / 动作</th><th scope="col">操作人</th><th scope="col">原因</th><th scope="col">记录时间</th></tr></x-slot:head>
                    @forelse($entries as $entry)<tr><td>版本 {{ $entry->sequence }}<span class="admin-ui-meta">{{ $entry->action }}</span></td><td>{{ $entry->actor_id === null ? '系统' : '#'.$entry->actor_id }}</td><td class="admin-ui-product-name">{{ $entry->reason }}</td><td>{{ $entry->created_at }}</td></tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>尚无追加处置记录</strong></td></tr>@endforelse
                </x-admin.table><x-admin.pagination :paginator="$entries" />
            </x-admin.card>
        @endif
        @can($authority)
            @php
                $caseOperations = $selected->status === 'resolved' ? ['reopen' => '重新打开']
                    : ($selected->kind === 'payment_attention' ? ['payment' => '核验并补记原笔'] : ['terminate_verified' => '认证核实原主机 Deleted', 'transfer_verified' => '登记已完成移交']);
            @endphp
            @foreach($caseOperations as $operation => $label)
                @include('filament.reconciliation.operation-form')
            @endforeach
        @endcan
    @endif
</div>
</x-filament-panels::page>
