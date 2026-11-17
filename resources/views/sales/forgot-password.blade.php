@extends('sales.layout')
@section('content')
<h1>找回密码</h1><p>重置链接发送到注册邮箱，默认 60 分钟内有效。<a href="/login">返回登录</a></p>
@if(!$mailReady)<p role="status">账户邮件服务尚未配置，请联系管理员。</p>@else
<form method="post" action="/forgot-password">@csrf<label>注册邮箱<input type="email" name="email" required maxlength="255" autocomplete="email" value="{{ old('email') }}"></label><button>发送重置链接</button></form>
@endif
@endsection
