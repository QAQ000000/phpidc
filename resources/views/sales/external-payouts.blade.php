@extends('sales.layout')
@section('title', '外部佣金提现')
@section('content')
<h1>外部佣金提现</h1><p><a href="/console/affiliate">返回推广佣金</a></p>
<p>可用佣金 {{ $user->affiliate_balance }} CNY · 待审占用 {{ $pending }} · 出款冻结 {{ $user->affiliate_frozen_balance }} CNY</p>
<p>财务审核后冻结佣金，人工转账并核验凭证后完成。每笔1.00–10000.00 CNY，手续费由本站承担。</p>
@if($hasReceivable)<p class="error">存在未清退款应收，暂不能申请提现，请联系财务核对。</p>@else
<form method="post" action="/console/affiliate/external">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}">
<label>金额（CNY）<input name="amount" required inputmode="decimal" pattern="[0-9]+\.[0-9]{2}" placeholder="10.00"></label>
<label>收款方式<select name="payout_method"><option value="bank_transfer">银行转账</option><option value="alipay">支付宝</option></select></label>
<label>收款人姓名<input name="payee_name" required maxlength="100" autocomplete="off"></label>
<label>收款账号<input name="payee_account" required maxlength="128" autocomplete="off"></label>
<label>开户银行（银行转账必填）<input name="bank_name" maxlength="100" autocomplete="off"></label>
<button>提交外部提现申请</button></form>@endif
<h2>我的申请</h2>@forelse($withdrawals as $withdrawal)<section><p>#{{ $withdrawal->id }} · {{ $withdrawal->amount }} CNY · {{ $withdrawal->payout_method }} · {{ $withdrawal->status }}</p><p>申请时间 {{ $withdrawal->created_at }} / 完成时间 {{ $withdrawal->completed_at ?? '待处理' }}</p></section>@empty<p>暂无外部提现申请。</p>@endforelse{{ $withdrawals->links('sales.pagination') }}
@endsection
