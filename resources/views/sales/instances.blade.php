@extends('sales.layout')
@section('title', '我的服务')
@section('content')
<x-console.page-header title="我的服务" description="查看服务状态、交付资料与续费账单。">
    <x-slot:actions><a class="console-button is-secondary" href="/store">选购服务</a></x-slot:actions>
</x-console.page-header>
<x-console.card title="服务列表" :description="'共 '.$instances->total().' 条 · 仅展示本账户记录'">
    <form method="get" action="/console/instances" class="filter-form">
        <label>服务名称、IP 或商品名<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="搜索服务"></label>
        <label>状态<select name="status"><option value="">全部状态</option>@foreach(\App\Support\CustomerLabels::INSTANCE_STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="console-actions"><button>筛选服务</button><a class="console-button is-secondary" href="/console/instances">重置</a></div>
    </form>
    @include('sales.instance-list')
    {{ $instances->links('sales.pagination') }}
</x-console.card>
@endsection
