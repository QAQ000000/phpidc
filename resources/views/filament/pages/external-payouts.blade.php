<x-filament-panels::page>
@include('filament.inventory-feedback')
<p>审批只冻结佣金；请在记录执行意图后按原标识人工转账。已支付须核对唯一凭证；不确定款保持冻结。</p>
<form method="get" class="inventory-form"><label>状态<select name="status"><option value="">全部</option>@foreach(['pending','reserved','processing','unknown','paid','failed','cancelled','rejected'] as $status)<option @selected(request('status')===$status)>{{ $status }}</option>@endforeach</select></label><button class="inventory-button">筛选</button></form>
@foreach($records as $record)<section class="inventory-box"><h2>申请 #{{ $record->id }} / 客户 #{{ $record->user_id }}</h2><p>{{ $record->amount }} CNY · {{ $record->payout_method }} · {{ $record->status }}<br>执行标识 {{ $record->payout_intent_key ?? '未生成' }}</p>
@can('finance.external-payout.details')<form method="post" action="/admin/external-payouts/{{ $record->id }}/details" class="inventory-form">@csrf<label>查看原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required> 确认查看收款资料</label><button class="inventory-button">查看收款信息</button></form>@endcan
@php($operations = match($record->status) {'pending'=>['approve'=>'批准并冻结','reject'=>'驳回'],'reserved'=>['start'=>'记录转账执行意图','cancel'=>'取消未执行出款'],'processing'=>['paid'=>'确认已完成付款','unknown'=>'标记结果不明','confirm_not_paid'=>'证明未支付并释放'],'unknown'=>['paid'=>'确认已完成付款','confirm_not_paid'=>'证明未支付并释放'],default=>[]})
@foreach($operations as $operation=>$label)
@php($permission = match($operation) {'approve','reject'=>'approve','start','cancel'=>'execute','paid'=>'confirm',default=>'reconcile'})
@can('finance.external-payout.'.$permission)
<form method="post" action="/admin/external-payouts/{{ $record->id }}" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="operation" value="{{ $operation }}"><input type="hidden" name="intent_key" value="{{ $record->payout_intent_key }}">
@if(in_array($operation,['paid','unknown','confirm_not_paid'],true))<label>核对证据<textarea name="evidence" required maxlength="1000"></textarea></label>@endif
@if($operation==='paid')<label>实际付款金额<input name="confirmed_amount" required value="{{ $record->amount }}"></label><input type="hidden" name="currency" value="CNY"><label>外部唯一交易号<input name="external_reference" required maxlength="128"></label>@endif
<label>原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required> 已核对本次操作、金额及原笔证据</label><button class="inventory-button">{{ $label }}</button></form>
@endcan
@endforeach
</section>@endforeach{{ $records->links() }}
</x-filament-panels::page>
