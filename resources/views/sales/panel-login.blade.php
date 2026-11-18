<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>登录控制面板</title></head>
<body>
<p>正在打开控制面板，请稍候。</p>
<form id="upstream-panel-login" method="post" action="{{ $panel['action'] }}" autocomplete="off">
@foreach($panel['fields'] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
<button type="submit">继续登录面板</button>
</form>
<script nonce="{{ $nonce }}">document.getElementById('upstream-panel-login').submit();</script>
</body></html>
