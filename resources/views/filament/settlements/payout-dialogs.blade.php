@foreach($records as $record)
    @php $operations = match($record->status) {'pending' => ['approve' => '批准并冻结', 'reject' => '驳回'], 'reserved' => ['start' => '记录转账执行意图', 'cancel' => '取消未执行出款'], 'processing' => ['paid' => '确认已完成付款', 'unknown' => '标记结果不明', 'confirm_not_paid' => '证明未支付并释放'], 'unknown' => ['paid' => '确认已完成付款', 'confirm_not_paid' => '证明未支付并释放'], default => []}; @endphp
    @foreach($operations as $operation => $label)
        @php
            $permission = match($operation) {'approve', 'reject' => 'approve', 'start', 'cancel' => 'execute', 'paid' => 'confirm', default => 'reconcile'};
            $restoreOperation = $errors->any() && in_array($formMarker, ['wallet-clearance', 'external-payout'], true) && old('_admin_form') === $formMarker && (int) old('record_id') === $record->id && old('operation') === $operation;
            $routine = in_array($operation, ['approve', 'start', 'paid'], true);
        @endphp
        @can($permissionPrefix.'.'.$permission)
            <x-admin.dialog :id="$formMarker.'-'.$record->id.'-'.$operation" :title="$label.' · 申请 #'.$record->id" :show-errors="$restoreOperation" x-init="{{ $restoreOperation ? '$nextTick(() => $el.showModal())' : '' }}">
                <p>原客户 #{{ $record->user_id }} · {{ $record->amount }} CNY。{{ $operation === 'start' ? '此操作仅记录人工转账执行意图，不会自动转账。' : '提交时按原笔状态、金额及执行标识重新核验。' }}</p>
                @if($restoreOperation && in_array($operation, ['paid', 'unknown', 'confirm_not_paid'], true))<p class="admin-operations-note">核对证据及外部交易号不保留，请重新输入。</p>@endif
                <form class="inventory-form admin-operations-form" method="post" action="{{ $pagePath }}/{{ $record->id }}">@csrf
                    <input type="hidden" name="_admin_form" value="{{ $formMarker }}"><input type="hidden" name="record_id" value="{{ $record->id }}"><input type="hidden" name="operation" value="{{ $operation }}">
                    <input type="hidden" name="request_id" value="{{ $restoreOperation ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="intent_key" value="{{ $record->payout_intent_key }}">
                    @if(in_array($operation, ['paid', 'unknown', 'confirm_not_paid'], true))<label class="admin-operations-wide">{{ $kind === 'wallet' ? '原笔核对证据' : '核对证据' }}<textarea name="evidence" required maxlength="1000"></textarea></label>@endif
                    @if($operation === 'paid')<label>实际付款金额<input name="confirmed_amount" required value="{{ $restoreOperation ? old('confirmed_amount', $record->amount) : $record->amount }}"></label><input type="hidden" name="currency" value="CNY"><label>外部唯一交易号<input name="external_reference" required maxlength="128" autocomplete="off"></label>@endif
                    <label class="admin-operations-wide">{{ $routine ? '备注（选填）' : '原因' }}<input name="reason" value="{{ $restoreOperation ? old('reason') : '' }}" @required(!$routine) maxlength="255"></label>
                    @if(in_array($operation, ['approve', 'start'], true))<input type="hidden" name="confirm" value="1">@else<label class="admin-operations-wide"><span><input type="checkbox" name="confirm" value="1" required @checked($restoreOperation && old('confirm'))> 已核对本次操作、金额及原笔证据</span></label>@endif
                    <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">{{ $label }}</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                </form>
            </x-admin.dialog>
        @endcan
    @endforeach
@endforeach
