@extends('sales.layout')
@section('title', 'Telegram 授权')
@section('content')
<h1>Telegram 授权</h1>
<p>请使用下方 Telegram 按钮完成授权。仅已绑定的账户可直接登录；首次使用请先注册、验证邮箱，再从账户安全页绑定。</p>
<script async src="https://telegram.org/js/telegram-widget.js?24" data-telegram-login="{{ $botUsername }}" data-size="large" data-userpic="false" data-auth-url="{{ $callbackUrl }}" data-request-access="write"></script>
<p>如果按钮无法加载，请检查网络，或使用<a href="/login">邮箱登录</a>。</p>
@endsection
