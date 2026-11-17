@extends('sales.layout')
@section('content')
<h1>{{ $instance->display_name }}</h1><p>状态：{{ $instance->status->value }}</p>
@if($instance->status === \App\Enums\InstanceStatus::ACTIVE && isset($instance->encrypted_credentials['card_key']))<p>卡密：<code>{{ $instance->encrypted_credentials['card_key'] }}</code></p>
@elseif($instance->status === \App\Enums\InstanceStatus::ACTIVE)
<p>IP：{{ $instance->primary_ip ?? '等待同步' }}</p>
@if(isset($instance->encrypted_credentials['username']))<p>用户名：{{ $instance->encrypted_credentials['username'] }}</p>@endif
@if(isset($instance->encrypted_credentials['password']))<p>密码：<code>{{ $instance->encrypted_credentials['password'] }}</code></p>@endif
@if(!$instance->encrypted_credentials)<p>开通信息同步中，请稍后刷新。</p>@endif
@else<p>尚未完成交付，请稍后刷新；未交付的订单请联系管理员。</p>@endif
@endsection
