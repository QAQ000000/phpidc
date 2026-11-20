@extends('sales.layout')
@section('title', '我的账单')
@section('content')
<x-console.page-header title="我的账单" :description="'共 '.$invoices->total().' 条 · 仅展示本账户记录'">
    <x-slot:actions><a class="console-button is-secondary" href="/console/wallet">钱包与充值</a></x-slot:actions>
</x-console.page-header>
<x-console.card title="账单记录" description="按账单号、商品名称或付款状态查找历史记录。">
    <form method="get" action="/console/invoices" class="filter-form">
        <label for="invoice-search">账单号或商品名<input id="invoice-search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="搜索账单号或商品"></label>
        <label for="invoice-status">状态<select id="invoice-status" name="status"><option value="">全部状态</option>@foreach(\App\Support\CustomerLabels::INVOICE_STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="console-actions"><button class="console-button">筛选账单</button><a class="console-button is-secondary" href="/console/invoices">重置</a></div>
    </form>
    @include('sales.invoice-list')
    {{ $invoices->links('sales.pagination') }}
</x-console.card>
@endsection
