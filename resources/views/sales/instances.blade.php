@extends('sales.layout')
@section('title', '我的服务')
@section('content')
<h1>我的服务</h1><p>共 {{ $instances->total() }} 条 · 仅展示本账户记录</p>
<form method="get" class="filter-form"><label>服务名称、IP 或商品名<input name="q" value="{{ request('q') }}" maxlength="100"></label><label>状态<select name="status"><option value="">全部状态</option>@foreach(\App\Support\CustomerLabels::INSTANCE_STATUSES as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></label><button>筛选服务</button><a href="/console/instances">重置</a></form>
@include('sales.instance-list')
{{ $instances->links('sales.pagination') }}
@endsection
