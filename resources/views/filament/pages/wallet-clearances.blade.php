<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="{{ asset('css/admin-operations.css') }}">
<div class="admin-ui admin-operations-page" x-data>
@include('filament.inventory-feedback')
@php $restoreClosed = $errors->any() && old('_admin_form') === 'closed-wallet-clearance'; @endphp
<header class="admin-ui-heading"><div><p>批准后冻结钱包余额；记录执行意图后人工转账。请按原执行标识核对，结果不明保持冻结，只有确证未付款才释放。</p></div>
    @can('finance.wallet-clearance.reconcile')<div class="admin-ui-actions"><button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('closed-wallet-clearance').showModal()">登记清退申请</button></div>@endcan
</header>
@include('filament.settlements.payout-list', ['kind' => 'wallet', 'pagePath' => '/admin/wallet-clearances', 'permissionPrefix' => 'finance.wallet-clearance', 'formMarker' => 'wallet-clearance'])
@can('finance.wallet-clearance.reconcile')
    <x-admin.card title="已注销原身份的已入账资金清退" description="核对原用户 ID、来源贷项和受款权利；复用的邮箱不能证明对原资金的权利。">
        <x-admin.table label="可核对资金来源"><x-slot:head><tr><th scope="col">来源案件</th><th scope="col">原用户</th><th scope="col">原贷项</th></tr></x-slot:head>
            @forelse($sources as $source)<tr><td>案件 #{{ $source->id }}</td><td>原用户 #{{ $source->user_id }}</td><td>原贷项 #{{ $source->source_credit_transaction_id }}</td></tr>@empty<tr><td colspan="3" class="admin-ui-empty"><strong>暂无待清退来源案件</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$sources" />
    </x-admin.card>
    <x-admin.dialog id="closed-wallet-clearance" title="登记清退申请" description="申请不自动付款，提交后仍须经过财务审核和原笔执行核验。" :show-errors="$restoreClosed" x-init="{{ $restoreClosed ? '$nextTick(() => $el.showModal())' : '' }}">
        @if($restoreClosed)<p class="admin-operations-note">收款资料及核验依据不保留，请重新输入。</p>@endif
        <form method="post" action="/admin/wallet-clearances/closed-account" class="inventory-form admin-operations-form">@csrf
            <input type="hidden" name="_admin_form" value="closed-wallet-clearance"><input type="hidden" name="request_id" value="{{ $restoreClosed ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}">
            <label>原用户 ID<input name="user_id" value="{{ $restoreClosed ? old('user_id') : '' }}" type="number" min="1" required></label>
            <label>来源案件 ID<input name="reconciliation_case_id" value="{{ $restoreClosed ? old('reconciliation_case_id') : '' }}" type="number" min="1" required></label>
            <label>结清金额（元）<input name="amount" inputmode="decimal" required maxlength="13" value="{{ $restoreClosed ? old('amount') : '' }}" placeholder="例如 10.00"></label>
            <label>收款方式<select name="payout_method"><option value="bank_transfer" @selected(!$restoreClosed || old('payout_method') !== 'alipay')>银行转账</option><option value="alipay" @selected($restoreClosed && old('payout_method') === 'alipay')>支付宝</option></select></label>
            <label>收款人姓名<input name="payee_name" required maxlength="100" autocomplete="off"></label><label>收款账号<input name="payee_account" required maxlength="128" autocomplete="off"></label><label class="admin-operations-wide">开户行（银行转账必填）<input name="bank_name" maxlength="100" autocomplete="off"></label>
            <label class="admin-operations-wide">受款权利及原身份核验依据<textarea name="evidence" required maxlength="1000"></textarea></label>
            <label class="admin-operations-wide">原因<input name="reason" value="{{ $restoreClosed ? old('reason') : '' }}" required maxlength="255"></label><label class="admin-operations-wide"><span><input type="checkbox" name="confirm" value="1" required @checked($restoreClosed && old('confirm'))> 已核对原身份、贷项和受款权利</span></label>
            <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">登记清退申请</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@endcan
</div>
</x-filament-panels::page>
