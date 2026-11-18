@extends('sales.layout')
@section('title', '我的账单')
@section('content')
<h1>我的账单</h1><p>共 {{ $invoices->total() }} 条 · 仅展示本账户记录 · <a href="/console/wallet">钱包与充值</a></p>
<form method="get" class="filter-form"><label>账单号或商品名<input name="q" value="{{ request('q') }}" maxlength="100"></label><label>状态<select name="status"><option value="">全部状态</option>@foreach(\App\Support\CustomerLabels::INVOICE_STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label><button>筛选账单</button><a href="/console/invoices">重置</a></form>
@include('sales.invoice-list')
{{ $invoices->links('sales.pagination') }}
@endsection
