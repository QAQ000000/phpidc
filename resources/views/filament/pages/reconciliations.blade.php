<x-filament-panels::page>
@include('filament.inventory-feedback')
<p>原告警、回调和绑定历史保留。案件状态须由真实入账、清退或原资源处置证据决定。</p>
@foreach($records as $record)<p><a href="/admin/reconciliations?case={{ $record->id }}">案件 #{{ $record->id }}</a> · 原客户 #{{ $record->user_id }} · {{ $record->kind }} · {{ $record->initial_reason }} · {{ $record->status }} · 版本 {{ $record->revision }}</p>@endforeach
{{ $records->links() }}
@if($selected)
<section class="inventory-box"><h2>案件 #{{ $selected->id }}</h2><p>原来源 {{ $selected->source_type }} #{{ $selected->source_id }} · {{ $selected->status }}</p>
@php($authority = $selected->kind === 'payment_attention' ? 'finance.reconcile' : 'upstream.orphan-reconcile')
@can($authority)
<form method="post" action="/admin/reconciliations/{{ $selected->id }}/evidence" class="inventory-form">@csrf<button class="inventory-button">查看核验证据</button></form>
@if($selected->status === 'resolved')
<form method="post" action="/admin/reconciliations/{{ $selected->id }}/reopen" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="expected_revision" value="{{ $selected->revision }}">
<label>推翻原结案的实际证据<textarea name="evidence" required maxlength="1000"></textarea></label><label>原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>原结案证据已不成立，申请重新打开核对</label><button class="inventory-button">重新打开</button></form>
@elseif($selected->kind === 'payment_attention')
<p>补记会重新计算当前账单状态和钱包容量。匿名原身份的已入账款须在<a href="/admin/wallet-clearances">钱包清退</a>中处理；未入账款不能直接外退。</p>
<form method="post" action="/admin/reconciliations/{{ $selected->id }}/payment" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="expected_revision" value="{{ $selected->revision }}">
<label>原笔核验证据<textarea name="evidence" required maxlength="1000"></textarea></label><label>原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>已核实原回调，按当前状态登记实际结果</label><button class="inventory-button">核验并补记原笔</button></form>
@else
<p>原资源必须认证核实 Deleted 或已有完整移交及责任接受证据。此入口只登记已完成事实。</p>
@foreach(['terminate_verified'=>'认证核实原主机 Deleted','transfer_verified'=>'登记已完成移交'] as $operation=>$label)
<form method="post" action="/admin/reconciliations/{{ $selected->id }}/binding" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="expected_revision" value="{{ $selected->revision }}"><input type="hidden" name="operation" value="{{ $operation }}">
@if($operation==='terminate_verified')<label>历史原端点（仅缺原快照时填写）<input name="original_base_url" type="url" maxlength="2048"></label><label>历史原端点证明<textarea name="historical_endpoint_evidence" maxlength="1000"></textarea></label>
@else<label>实际接收主体<input name="recipient" required maxlength="255"></label><label>被移交目标资源<input name="target_resource" required maxlength="255"></label><label>责任接受凭证编号<input name="acceptance_reference" required maxlength="255"></label><label>实际接受时间<input name="accepted_at" type="datetime-local" required></label><label><input type="checkbox" name="responsibility_accepted" value="1" required>已核实接收方接受该原资源及后续责任</label>@endif
<label>原笔处置证据<textarea name="evidence" required maxlength="1000"></textarea></label><label>原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>已核对原绑定及本次事实</label><button class="inventory-button">{{ $label }}</button></form>
@endforeach
@endif
@endcan
@if($entries)<h3>追加处置历史</h3>@foreach($entries as $entry)<p>版本 {{ $entry->sequence }} · {{ $entry->action }} · 操作人 #{{ $entry->actor_id ?? 'system' }} · {{ $entry->reason }} · {{ $entry->created_at }}</p>@endforeach{{ $entries->links() }}@endif
</section>
@endif
</x-filament-panels::page>
