<x-filament-panels::page>
@include('filament.inventory-feedback')
<p>批准后冻结钱包余额；记录执行意图后人工转账。请按原执行标识核对，结果不明保持冻结，只有确证未付款才释放。</p>
@can('finance.wallet-clearance.reconcile')
<section class="inventory-box"><h2>已注销原身份的已入账资金清退</h2>
<p>核对原用户 ID、来源贷项和受款权利；复用的邮箱不能证明对原资金的权利。</p>
@if($sources)<p>可核对来源：</p>@foreach($sources as $source)<p>案件 #{{ $source->id }} / 原用户 #{{ $source->user_id }} / 原贷项 #{{ $source->source_credit_transaction_id }}</p>@endforeach{{ $sources->links() }}@endif
<form method="post" action="/admin/wallet-clearances/closed-account" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ old('user_id') ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}">
<label>原用户 ID<input name="user_id" value="{{ old('user_id') }}" type="number" min="1" required></label><label>来源案件 ID<input name="reconciliation_case_id" value="{{ old('reconciliation_case_id') }}" type="number" min="1" required></label>
@include('sales.wallet-clearance-payee')
<label>受款权利及原身份核验依据<textarea name="evidence" required maxlength="1000">{{ old('user_id') ? old('evidence') : '' }}</textarea></label>
<label>原因<input name="reason" value="{{ (int) old('record_id') === $record->id && old('operation') === $operation ? old('reason') : '' }}" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>已核对原身份、贷项和受款权利</label><button class="inventory-button">登记清退申请</button>
</form></section>
@endcan
@foreach($records as $record)<section class="inventory-box"><h2>申请 #{{ $record->id }} / 原客户 #{{ $record->user_id }}</h2><p>{{ $record->amount }} CNY · {{ $record->payout_method }} · {{ $record->scenario }} · {{ $record->status }}<br>执行标识 {{ $record->payout_intent_key ?? '未生成' }}</p>
@can('finance.wallet-clearance.details')<form method="post" action="/admin/wallet-clearances/{{ $record->id }}/details" class="inventory-form">@csrf<label>查看原因<input name="reason" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>确认查看原笔收款资料</label><button class="inventory-button">查看收款资料</button></form>@endcan
@php($operations = match($record->status) {'pending'=>['approve'=>'批准并冻结','reject'=>'驳回'],'reserved'=>['start'=>'记录转账执行意图','cancel'=>'取消未执行出款'],'processing'=>['paid'=>'确认已完成付款','unknown'=>'标记结果不明','confirm_not_paid'=>'证明未支付并释放'],'unknown'=>['paid'=>'确认已完成付款','confirm_not_paid'=>'证明未支付并释放'],default=>[]})
@foreach($operations as $operation=>$label)
@php($permission = match($operation) {'approve','reject'=>'approve','start','cancel'=>'execute','paid'=>'confirm',default=>'reconcile'})
@can('finance.wallet-clearance.'.$permission)
<form method="post" action="/admin/wallet-clearances/{{ $record->id }}" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (int) old('record_id') === $record->id && old('operation') === $operation ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="record_id" value="{{ $record->id }}"><input type="hidden" name="operation" value="{{ $operation }}"><input type="hidden" name="intent_key" value="{{ $record->payout_intent_key }}">
@if(in_array($operation,['paid','unknown','confirm_not_paid'],true))<label>原笔核对证据<textarea name="evidence" required maxlength="1000"></textarea></label>@endif
@if($operation==='paid')<label>实际付款金额<input name="confirmed_amount" required value="{{ $record->amount }}"></label><input type="hidden" name="currency" value="CNY"><label>外部唯一交易号<input name="external_reference" required maxlength="128"></label>@endif
<label>原因<input name="reason" value="{{ (int) old('record_id') === $record->id && old('operation') === $operation ? old('reason') : '' }}" required maxlength="255"></label><label><input type="checkbox" name="confirm" value="1" required>已核对本次操作、金额及原笔证据</label><button class="inventory-button">{{ $label }}</button></form>
@endcan
@endforeach
</section>@endforeach{{ $records->links() }}
</x-filament-panels::page>
