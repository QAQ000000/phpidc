@extends('sales.layout')
@section('title', '钱包结清收款资料')
@section('content')
<h1>钱包结清 #{{ $clearance->id }}</h1>
<p>原客户 #{{ $clearance->user_id }} · {{ $clearance->amount }} {{ $clearance->currency }} · {{ $clearance->status }}</p>
<dl><dt>收款人</dt><dd>{{ $details['name'] ?? '' }}</dd><dt>账号</dt><dd>{{ $details['account'] ?? '' }}</dd><dt>开户行</dt><dd>{{ $details['bank'] ?? '' }}</dd></dl>
@if($evidence)<h2>原笔核验资料</h2><pre>{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>@endif
<p><a href="/admin/wallet-clearances">返回钱包结清</a></p>
@endsection
