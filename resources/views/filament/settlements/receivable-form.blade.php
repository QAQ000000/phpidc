@php
    $channelOptions = ['bank' => '银行转账', 'alipay' => '支付宝', 'wechat' => '微信支付'];
    $selectedChannel = $restoreSettlement && is_string(old('external_channel')) ? old('external_channel') : 'bank';
@endphp
<x-admin.dialog id="receivable-settlement" title="登记已核实收到的外部款项" :show-errors="$restoreSettlement" x-init="{{ $restoreSettlement ? '$nextTick(() => $el.showModal())' : '' }}">
    <p>应收 #{{ $selected->id }} · 用户 #{{ $selected->user_id }} · 当前剩余 {{ $projection['remaining'] }} CNY。</p>
    @if($restoreSettlement && !$correction)<p class="admin-operations-note">收款凭证号不保留，请重新输入。</p>@endif
    <form method="post" action="/admin/finance/receivables/{{ $selected->id }}/settlements" class="inventory-form admin-operations-form" x-data="{ customChannel: {{ !array_key_exists($selectedChannel, $channelOptions) ? 'true' : 'false' }} }">@csrf
        <input type="hidden" name="_admin_form" value="receivable-settlement"><input type="hidden" name="clawback_id" value="{{ $selected->id }}"><input type="hidden" name="request_id" value="{{ $restoreSettlement ? old('request_id') : $requestId }}"><input type="hidden" name="version" value="{{ $projection['version'] }}"><input type="hidden" name="source" value="external_received">
        <label>本次已收金额 CNY<input name="amount" value="{{ $restoreSettlement ? old('amount', $correction?->amount) : $correction?->amount }}" required pattern="[0-9]{1,10}\.[0-9]{2}" placeholder="0.00"></label>
        @if($correction)
            <input type="hidden" name="corrects_settlement_id" value="{{ $correction->id }}"><input type="hidden" name="external_channel" value="{{ $correction->external_channel }}"><input type="hidden" name="external_reference" value="{{ $correction->external_reference }}">
            <p>沿用原收款渠道：{{ $channelOptions[$correction->external_channel] ?? $correction->external_channel }}；凭证尾号：{{ substr($correction->external_reference, -4) }}。</p>
        @else
            <label>收款渠道<select name="external_channel" @change="customChannel = $event.target.value === ''">@foreach($channelOptions as $channel => $label)<option value="{{ $channel }}" @selected($selectedChannel === $channel)>{{ $label }}</option>@endforeach<option value="" @selected(!array_key_exists($selectedChannel, $channelOptions))>其他渠道</option></select></label>
            <label x-show="customChannel">其他渠道标识<input name="custom_external_channel" value="{{ $restoreSettlement ? old('custom_external_channel', array_key_exists($selectedChannel, $channelOptions) ? '' : $selectedChannel) : '' }}" :required="customChannel" :disabled="!customChannel" pattern="[a-z][a-z0-9_\-]{0,31}" maxlength="32" placeholder="例如 paypal"></label>
            <label>收款凭证号<input name="external_reference" required maxlength="128" autocomplete="off"></label>
        @endif
        <label>实际收款时间<input name="received_at" type="datetime-local" required value="{{ $restoreSettlement ? old('received_at', ($correction?->received_at ?? now())->format('Y-m-d\TH:i')) : ($correction?->received_at ?? now())->format('Y-m-d\TH:i') }}"></label>
        <label class="admin-operations-wide">备注（选填）<input name="reason" value="{{ $restoreSettlement ? old('reason') : '' }}" maxlength="255"></label>
        <label class="admin-operations-wide"><span><input type="checkbox" name="confirm" value="1" required @checked($restoreSettlement && old('confirm'))> 已经核实实际收到上述 CNY 款项，凭证未分配给其他有效清偿</span></label>
        <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">登记已收款</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
    </form>
</x-admin.dialog>
