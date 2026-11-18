@extends('sales.layout')
@section('title', '外部提现收款信息')
@section('content')
<h1>外部提现 #{{ $withdrawal->id }} 收款信息</h1><p><a href="/admin/external-payouts">返回财务处理</a></p>
<p>金额 {{ $withdrawal->amount }} CNY · 状态 {{ $withdrawal->status }} · 方式 {{ $withdrawal->payout_method }}</p>
<p>姓名：{{ $details['name'] ?? '历史资料缺失' }}<br>账号：{{ $details['account'] ?? '历史资料缺失' }}<br>银行：{{ $details['bank'] ?? '' }}</p>
<p>本次查看已记录审计。请核对固定收款信息和原执行标识：{{ $withdrawal->payout_intent_key ?? '尚未开始执行' }}。</p>
@endsection
