@extends('sales.layout')
@section('content')
<h1>登录</h1><form method="post" action="/login">@csrf<label>邮箱 <input name="email" type="email" required></label><label>密码 <input name="password" type="password" required></label><button>登录</button></form>
<h2>注册</h2><form method="post" action="/register">@csrf<label>姓名 <input name="name" required></label><label>邮箱 <input name="email" type="email" required></label><label>密码（至少 12 位） <input name="password" type="password" minlength="12" required></label><label>确认密码 <input name="password_confirmation" type="password" required></label><button>注册</button></form>
@endsection
