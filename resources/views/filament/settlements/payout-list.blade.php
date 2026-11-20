@php
    $statusLabels = ['pending' => '待审核', 'reserved' => '已冻结', 'processing' => '付款处理中', 'unknown' => '结果待核对', 'paid' => '已付款', 'failed' => '未支付已释放', 'cancelled' => '已取消', 'rejected' => '已驳回'];
    $methodLabels = ['bank_transfer' => '银行转账', 'alipay' => '支付宝'];
@endphp
<x-admin.card title="申请列表">
    @if($kind === 'external')<x-admin.filters :action="$pagePath"><label>状态<select name="status"><option value="">全部</option>@foreach($statusLabels as $status => $statusLabel)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $statusLabel }}</option>@endforeach</select></label></x-admin.filters>@endif
    <x-admin.table :label="$kind === 'external' ? '外部佣金提现申请' : '钱包结清申请'"><x-slot:head><tr><th scope="col">申请 / 原客户</th><th scope="col" class="admin-ui-number">金额 / 方式</th><th scope="col">状态 / 执行标识</th><th scope="col">申请 / 完成时间</th><th scope="col" class="admin-ui-row-actions">处理</th></tr></x-slot:head>
        @forelse($records as $record)<tr>
            <td>申请 #{{ $record->id }}<span class="admin-ui-meta">原客户 #{{ $record->user_id }}@if($kind === 'wallet') · {{ match($record->scenario) { 'closed_account_receipt' => '已注销原身份清退', 'closure_balance' => '本人钱包结清', default => $record->scenario } }}@endif</span></td>
            <td class="admin-ui-number admin-ui-nowrap">{{ $record->amount }} CNY<span class="admin-ui-meta">{{ $methodLabels[$record->payout_method] ?? $record->payout_method }}</span></td>
            <td><x-admin.badge :tone="match($record->status) { 'paid' => 'success', 'unknown' => 'danger', 'pending', 'reserved', 'processing' => 'warning', default => 'neutral' }">{{ $statusLabels[$record->status] ?? $record->status }}</x-admin.badge><span class="admin-ui-meta">执行标识 {{ $record->payout_intent_key ?? '未生成' }}</span></td>
            <td>{{ $record->created_at }}<span class="admin-ui-meta">{{ $record->completed_at ?? '尚未完成' }}</span></td>
            <td class="admin-ui-row-actions">
                @can($permissionPrefix.'.details')<form method="post" action="{{ $pagePath }}/{{ $record->id }}/details">@csrf<button class="admin-ui-link">{{ $kind === 'external' ? '查看收款信息' : '查看收款资料' }}</button></form>@endcan
                @php($operations = match($record->status) {'pending' => ['approve' => '批准并冻结', 'reject' => '驳回'], 'reserved' => ['start' => '记录转账执行意图', 'cancel' => '取消未执行出款'], 'processing' => ['paid' => '确认已完成付款', 'unknown' => '标记结果不明', 'confirm_not_paid' => '证明未支付并释放'], 'unknown' => ['paid' => '确认已完成付款', 'confirm_not_paid' => '证明未支付并释放'], default => []})
                @foreach($operations as $operation => $label)@php($permission = match($operation) {'approve', 'reject' => 'approve', 'start', 'cancel' => 'execute', 'paid' => 'confirm', default => 'reconcile'})
                    @can($permissionPrefix.'.'.$permission)<p><button type="button" class="admin-ui-link" x-on:click="document.getElementById('{{ $formMarker }}-{{ $record->id }}-{{ $operation }}').showModal()">{{ $label }}</button></p>@endcan
                @endforeach
                @if($operations === [])<span class="admin-ui-meta">已结束，无阶段操作</span>@endif
            </td>
        </tr>@empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无匹配申请</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
@include('filament.settlements.payout-dialogs')
