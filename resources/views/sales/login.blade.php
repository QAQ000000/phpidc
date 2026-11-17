@extends('sales.layout')
@section('title', '登录账户')
@section('content')
<h1>登录</h1><form method="post" action="/login" class="account-form">@csrf<label>邮箱 <input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"></label><label>密码 <input name="password" type="password" required maxlength="255" autocomplete="current-password"></label><label><input type="checkbox" name="remember" value="1">记住登录</label><button>登录</button></form>
<p><a href="/forgot-password">忘记密码？</a> · <a href="/register">注册新账户</a></p>
@foreach($providers as $provider => $details)@if($details['enabled'])<form method="post" action="/auth/{{ $provider }}/redirect">@csrf<button>使用 {{ $details['label'] }} 登录</button></form>@endif@endforeach
@endsection
