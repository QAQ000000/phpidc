@extends('sales.layout')
@section('title', '机器状态与监控图表')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p>
<h1>{{ $instance->display_name }} · 机器状态与监控图表</h1>
<p>业务状态：{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}。以下机器运行状态独立查询；图表请求最近 24 小时，时间标签沿用上游返回值。</p>
<form method="post" action="/console/instances/{{ $instance->id }}/telemetry">@csrf<button type="submit">刷新机器状态与图表</button></form>
@foreach($widgets as $widget)
@include('sales.telemetry-widget', ['widget' => $widget])
@endforeach
@endsection
