@extends('sales.layout')
@section('title', '归档一次性交付权益')
@section('content')
<p><a href="/console/instances">返回我的服务</a></p>
<h1>归档一次性交付权益</h1>
<p>服务：{{ $instance->display_name }}（#{{ $instance->id }}）</p>
<p>仅已付款并完整交付的卡密或固定兑换码可以归档。归档记录表示本次交付已经完成，供注销账户前核对使用。</p>
<p>归档不回收或重新销售已售卡密，也不撤销外部兑换权利。原服务、付款和交付记录仍会保留，其他待处理业务仍须完成后才能注销。</p>
<p><strong>请先保存卡密、兑换地址及所需交付资料。账户注销后将无法登录查看，重新注册也不会继承原服务或凭据。</strong></p>
@if($validArchive)
<p role="status">已归档：{{ $archive->archived_at }}。当前交付证据核验通过。</p>
<p><a href="/console/account/close">查看注销条件</a></p>
@elseif($archive)
<p role="alert">原归档与当前交付证据不一致，请联系管理员核对；历史归档不能覆盖。</p>
@elseif($reasons)
<div role="alert"><p>暂不能归档：</p><ul>@foreach($reasons as $messages)@foreach($messages as $message)<li>{{ $message }}</li>@endforeach@endforeach</ul></div>
@else
<form method="post" action="/console/instances/{{ $instance->id }}/archive">@csrf
<input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::ulid()) }}">
<label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label>
<label><input type="checkbox" name="confirm" value="1" required>我已保存交付资料，确认本次一次性交付已完成，并知悉归档及注销后的资料留存规则</label>
<button>确认归档交付权益</button>
</form>
@endif
<p><a href="/console/instances/{{ $instance->id }}">查看服务与交付资料</a></p>
@endsection
