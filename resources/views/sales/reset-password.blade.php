@extends('sales.layout')
@section('content')
<h1>设置新密码</h1><form method="post" action="/reset-password">@csrf
<input type="hidden" name="token" value="{{ $token }}"><label>邮箱<input type="email" name="email" value="{{ $email }}" required autocomplete="email"></label>
<label>新密码（至少 12 位）<input type="password" name="password" minlength="12" maxlength="255" required autocomplete="new-password"></label><label>确认新密码<input type="password" name="password_confirmation" required autocomplete="new-password"></label><button>重置密码</button></form>
@endsection
