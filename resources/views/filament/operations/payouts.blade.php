<header class="admin-ui-heading"><div><p>本页审核转本站钱包；<a class="admin-ui-link" href="/admin/external-payouts">外部提现</a>单独处理。pending 占用可申请额度。退款可追回推广余额，审核时再次校验。应收未清偿时禁止批准。</p></div></header>
<x-admin.card title="转钱包申请">
    <x-admin.filters action="/admin/payouts"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>状态<select name="status"><option value="">全部</option>@foreach(['pending' => '待审核', 'approved' => '已批准', 'rejected' => '已驳回'] as $status => $label)<option value="{{ $status }}" @selected(request('status') === $status)>{{ $label }}</option>@endforeach</select></label></x-admin.filters>
    <x-admin.table label="转钱包申请"><x-slot:head><tr><th scope="col">申请 / 用户</th><th scope="col" class="admin-ui-number">金额 / 状态</th><th scope="col">资金与应收核对</th><th scope="col" class="admin-ui-row-actions">审核 / 操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr>
            <td>申请 #{{ $record->id }}<span class="admin-ui-meta">用户 #{{ $record->user_id }} · 本站钱包</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $record->amount }} CNY<span class="admin-ui-meta"><x-admin.badge :tone="$record->status === 'pending' ? 'warning' : ($record->status === 'approved' ? 'success' : 'neutral')">{{ match($record->status) { 'pending' => '待审核', 'approved' => '已批准', 'rejected' => '已驳回', default => $record->status } }}</x-admin.badge></span></td>
            <td class="admin-operations-payout-facts"><p>当前推广余额 {{ $payoutUsers->get($record->user_id)?->affiliate_balance ?? '账户不可用' }} / 申请占用 {{ $payoutFunds[$record->user_id]['pending'] ?? '—' }} CNY</p>
                @if($payoutFunds[$record->user_id]['checking'] ?? true)<p class="admin-operations-note">完整应收历史待核对，当前未判定为无债。<a class="admin-ui-link" href="{{ $payoutFunds[$record->user_id]['check_url'] ?? '/admin/finance/receivables?user_id='.$record->user_id }}">查看完整核验或全部应收</a></p>@endif
                @if($payoutFunds[$record->user_id]['receivable'] ?? false)<details><summary>应收或核验尚未结清</summary><p class="admin-operations-note">批准前须完整核对。以下最多展示本用户两笔，<a class="admin-ui-link" href="/admin/finance/receivables?user_id={{ $record->user_id }}">查看全部应收</a>：</p>
                    @foreach($receivables->where('user_id', $record->user_id) as $entry)@if(!$entry['valid'] || \App\Support\Money::gt($entry['remaining'], '0.00'))<p>流水 #{{ $entry['id'] }} / 原账单 #{{ $entry['invoice_id'] }} / 剩余应收 {{ $entry['valid'] ? $entry['remaining'].' CNY' : '证据异常，禁止批准' }} · <a class="admin-ui-link" href="/admin/finance/receivables?clawback={{ $entry['id'] }}">核对清偿</a></p>@endif @endforeach
                </details>@endif
            </td><td class="admin-ui-row-actions"><span class="admin-ui-meta">审核人 #{{ $record->processed_by ?? '—' }}<br>{{ $record->processed_at ?? '待审核' }}</span>
                @can('finance.payout')@if($record->status === 'pending' && $record->destination === 'wallet')<button type="button" class="admin-ui-link" x-on:click="document.getElementById('payout-review-{{ $record->id }}').showModal()">审核申请</button>@endif @endcan
            </td>
        </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无匹配申请</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
@can('finance.payout')
    @foreach($records as $record)@if($record->status === 'pending' && $record->destination === 'wallet')
        @php($restorePayout = $restoreContext === 'payout' && (int) old('record_id') === $record->id)
        <x-admin.dialog :id="'payout-review-'.$record->id" :title="'审核转钱包申请 #'.$record->id" :show-errors="$restorePayout" x-init="{{ $restorePayout ? '$nextTick(() => $el.showModal())' : '' }}">
            <p>用户 #{{ $record->user_id }} · {{ $record->amount }} CNY。批准只转入本站钱包，提交时重新核验余额与退款应收。</p>
            <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/payouts/{{ $record->id }}" x-data="{ decision: @js($restorePayout ? old('decision', 'reject') : 'reject') }">@csrf
                <input type="hidden" name="_admin_form" value="payout"><input type="hidden" name="record_id" value="{{ $record->id }}">
                <label>决定<select name="decision" x-model="decision"><option value="reject" @selected(!$restorePayout || old('decision') !== 'approve')>驳回</option><option value="approve" @selected($restorePayout && old('decision') === 'approve')>批准转钱包</option></select></label>
                <label>审核备注（驳回时必填）<input name="reason" value="{{ $restorePayout ? old('reason') : '' }}" x-bind:required="decision === 'reject'" maxlength="255"></label>
                <label class="admin-operations-wide"><span><input type="checkbox" name="confirm" value="1" required @checked($restorePayout && old('confirm'))> 已核查金额与退款应收</span></label>
                <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">确认处理</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif @endforeach
@endcan
