@extends('sales.layout')
@section('title', '继续实名认证')
@section('content')
<h1>继续实名认证</h1><p>申请 #{{ $verification->id }} · {{ $verification->masked_summary['name'] ?? '' }} · 证件末四位 {{ $verification->masked_summary['id_last4'] ?? '' }}</p>
<p><a href="{{ $authorizationUrl }}" rel="noreferrer noopener nofollow" target="_blank">打开认证平台完成认证</a></p>
<p>请在认证平台核对信息并完成认证。关闭或返回此页面不会自动标记通过。</p>
<form method="post" action="/console/account/kyc/{{ $verification->id }}/refresh">@csrf<button>查询认证结果</button></form>
<p><a href="/console/account">返回账户安全</a></p>
@endsection
