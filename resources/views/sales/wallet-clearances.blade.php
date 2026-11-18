@extends('sales.layout')
@section('title', '钱包结清')
@section('content')
<h1>钱包结清</h1>
<p>可用 {{ $user->credit }} CNY · 结清冻结 {{ $user->wallet_clearance_frozen }} CNY</p>
<p>每笔 0.01–10000.00 元，人工核验后转账，手续费由商家承担。同一时间只处理一笔申请；结果不明时资金继续冻结，等待原笔核对。</p>
<form method="post" action="/console/wallet/clearances" class="account-form">@csrf
<input type="hidden" name="request_id" value="{{ old('request_id', $requestId) }}">
@include('sales.wallet-clearance-payee')
<label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label>
<label><input type="checkbox" name="confirm" value="1" required>我确认金额及收款资料，申请结清本人钱包余额</label>
<button>提交申请</button></form>
<h2>申请记录</h2>
@foreach($clearances as $record)<section><p>申请 #{{ $record->id }} · {{ $record->amount }} CNY · {{ $record->payout_method }} · {{ $record->status }} · {{ $record->created_at }}</p></section>@endforeach
{{ $clearances->links('sales.pagination') }}
<p><a href="/console/wallet">返回钱包</a></p>
@endsection
