    @if($offlineAllowed)
    <x-admin.dialog id="invoice-offline-receipt" title="登记已收到的线下款" description="仅登记已实际收到的银行转账或现金，准确结清原网关应付额；此操作不向银行或支付网关发起收款。" :show-errors="$restoreOffline" x-init="{{ $restoreOffline ? '$nextTick(() => $el.showModal())' : '' }}">
        <form method="post" action="{{ $workspaceAction('/admin/finance/invoices/'.$invoice->id.'/offline-receipt') }}" class="admin-finance-form">@csrf
            <input type="hidden" name="_admin_form" value="offline-receipt"><input type="hidden" name="invoice_id" value="{{ $invoice->id }}"><input type="hidden" name="request_id" value="{{ $offlineRequest }}"><input type="hidden" name="currency" value="CNY">
            <p class="admin-finance-wide">账单 {{ $invoice->invoice_no }} · 客户 #{{ $invoice->user_id }}</p>
            <label>本次实收金额（CNY）<input name="amount" value="{{ $invoice->gateway_amount }}" readonly aria-readonly="true"><span class="admin-ui-meta">固定为原网关应付额，提交时重新核对。</span></label>
            <label>实际收款渠道<select name="external_channel" required><option value="bank_transfer" @selected($offlineValue('external_channel') !== 'cash')>银行转账</option><option value="cash" @selected($offlineValue('external_channel') === 'cash')>现金</option></select></label>
            <label>收款凭证编号<input name="external_reference" required maxlength="128" value="" autocomplete="off"></label>
            <label>实际收款时间<input type="datetime-local" name="received_at" required max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ $offlineValue('received_at') }}"></label>
            <label class="admin-finance-wide">凭证说明（可选，加密保存）<textarea name="evidence" maxlength="2000" rows="3" autocomplete="off"></textarea><span class="admin-ui-meta">为保护凭证，验证失败后请重新填写凭证编号与说明。</span></label>
            <label class="admin-finance-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $offlineValue('reason') }}"></label>
            <label class="admin-finance-wide"><span><input type="checkbox" name="confirm" value="1" required> 已核对实际到账、客户、金额与凭证，确认登记这笔线下收款。</span></label>
            <div class="admin-finance-form-actions admin-finance-wide"><button class="admin-ui-button admin-ui-button-primary">确认登记收款</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
    @if($offlineReceipt && $canRecordOffline)
    <x-admin.dialog id="offline-receipt-details" title="完整线下收款凭证" description="本次查看记录实际管理员审计；关闭后清除页面中的完整凭证。" x-data="{ loading: false, reference: '', evidence: '', message: '' }" x-on:close="reference = ''; evidence = ''; message = ''" x-on:offline-receipt-read.window="loading = true; reference = ''; evidence = ''; message = ''; fetch($el.querySelector('form').action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData($el.querySelector('form')) }).then(async response => { if (!response.ok) throw new Error('凭证读取失败，请核对权限后重试。'); return response.json(); }).then(data => { if ($el.open) { reference = data.external_reference; evidence = data.evidence || '未填写凭证说明'; } }).catch(error => { if ($el.open) message = error.message; }).finally(() => { loading = false; })">
        <form method="post" action="/admin/finance/offline-receipts/{{ $offlineReceipt['id'] }}/details">@csrf</form>
        <p x-show="loading" role="status">正在读取凭证…</p><p x-show="message" x-text="message" role="alert"></p>
        <dl class="admin-ui-facts" x-show="!loading && !message"><div><dt>完整凭证编号</dt><dd x-text="reference"></dd></div><div><dt>凭证说明</dt><dd class="admin-finance-evidence" x-text="evidence"></dd></div></dl>
    </x-admin.dialog>
    @endif
    @if($canRefund)
    <x-admin.dialog id="invoice-refund" :title="'全额退款 '.$invoice->total_amount.' '.$invoice->currency" description="先确认服务已终止。若明确豁免，必须单独勾选保留服务；已交付卡密不会回收，退款会记录损失。佣金不足扣回的差额登记应收，仍需人工处理。" :show-errors="$restoreRefund" x-init="{{ $restoreRefund ? '$nextTick(() => $el.showModal())' : '' }}">
                        <form method="post" action="{{ $workspaceAction('/admin/finance/invoices/'.$invoice->id.'/refund') }}" class="inventory-form" x-data="{ mode: '{{ $refundInput && old('mode') === 'external_record' ? 'external_record' : 'refund_wallet' }}', keepService: {{ ($instance === null || !$instance->status->isTerminal()) && $refundInput && old('keep_service') === '1' ? 'true' : 'false' }} }">@csrf
                            <input type="hidden" name="ui_form" value="refund-{{ $invoice->id }}">
                            <label>处理方式<select name="mode" required x-model="mode"><option value="refund_wallet" @selected(!$refundInput || old('mode') !== 'external_record')>退回客户钱包</option><option value="external_record" @selected($refundInput && old('mode') === 'external_record')>登记已完成的外部退款</option></select></label>
                            <label>{{ $instance !== null && $instance->status->isTerminal() ? '备注（选填）' : '退款说明（保留服务时必填）' }}<input name="reason" value="{{ $refundInput && is_string(old('reason')) ? old('reason') : '' }}" :required="keepService" maxlength="255" placeholder="有特殊情况时补充说明"></label>
                            @if($instance === null || !$instance->status->isTerminal())<label><span><input type="checkbox" name="keep_service" value="1" x-model="keepService"> 明确豁免终止前置条件，接受保留服务的损失</span></label>@endif
                            <label x-show="mode === 'external_record'"><span><input type="checkbox" name="external_confirm" value="1" :disabled="mode !== 'external_record'" :required="mode === 'external_record'"> 外部退款已经完成（本系统不打款）</span></label>
                            <label><span><input type="checkbox" name="confirm" value="1" required> 已核对客户、全额金额、服务状态及退款方式</span></label>
                            <div class="admin-finance-form-actions"><button class="admin-ui-button admin-ui-button-danger">确认全额退款</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                        </form>
    </x-admin.dialog>
    @endif
