<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>卡密商城</title>
<style>body{font:16px system-ui;max-width:960px;margin:40px auto;padding:0 20px;color:#182230}a{color:#175cd3}section{border:1px solid #ddd;padding:20px;margin:20px 0}label{display:block;margin:10px 0}input,select,button{font:inherit;padding:8px}code{overflow-wrap:anywhere}li{margin:8px 0}.error{color:#b42318}</style></head><body>
<nav><a href="/console">卡密商城 / 我的订单</a> @auth <form method="post" action="/logout" style="display:inline">@csrf<button>退出</button></form>@endauth</nav>
@if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</body></html>
