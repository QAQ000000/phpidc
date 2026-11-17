@extends('sales.layout')
@section('title', '注册账户')
@section('content')
<h1>注册账户</h1><p>已有账户？<a href="/login">前往登录</a></p>
<form method="post" action="/register" class="account-form">@csrf
<label>姓名<input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name"></label>
<label>邮箱<input name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"></label>
<label>密码（至少 12 位）<input name="password" type="password" minlength="12" maxlength="255" required autocomplete="new-password"></label>
<label>确认密码<input name="password_confirmation" type="password" required autocomplete="new-password"></label>
<p>注册后请在账户安全页验证邮箱，用于账户找回与第三方绑定。</p><button>注册</button></form>
@endsection
