@extends('sales.layout')
@section('title', '机器电源状态')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p>
<h1>{{ $instance->display_name }} · 机器电源状态</h1>
@include('sales.power-status-form')
@endsection
