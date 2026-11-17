@extends('sales.layout')
@section('content')
<h1>{{ $instance->display_name }}</h1><p>状态：{{ $instance->status->value }}</p>
@if($instance->status === \App\Enums\InstanceStatus::ACTIVE && isset($instance->encrypted_credentials['card_key']))<p>卡密：<code>{{ $instance->encrypted_credentials['card_key'] }}</code></p>
@else<p>尚未完成交付，请稍后刷新；未交付的订单请联系管理员。</p>@endif
@endsection
