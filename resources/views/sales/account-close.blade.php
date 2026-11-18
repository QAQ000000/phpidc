@extends('sales.layout')
@section('title', '注销账户')
@section('content')
<h1>注销账户</h1>
@if(isset($receivableCheck['receivable_check_url'][0]))<p><a href="{{ $receivableCheck['receivable_check_url'][0] }}">查看完整核验进度</a></p>@endif
<p>已完成的一次性交付可在服务详情中由本人确认归档。请先保存交付资料；注销后将无法再登录读取，归档不会撤销兑换权或回收已售卡密。钱包余款请先<a href="/console/wallet/clearances">申请结清</a>。</p>
<p>注销不可恢复。当前姓名、邮箱、手机号及认证资料会匿名化，登录会话和身份、通知绑定会失效。原邮箱、手机号和第三方身份可以用于新账户，新账户不会继承原订单、服务、余额、实名或凭据。</p>
<p>历史账单、支付、财务流水、履约与交付、已关闭工单、实名核验脱敏摘要和外部申请关联、提现及审计证据按原有受限权限保留；其中可能含历史自由文本、服务资料或加密凭据。通知日志的收件目标另行匿名化。此操作不会删除供应商保存的资料，也不能撤回已经发往供应商的通知。</p>
<p>注销不会扣除、没收或自动退还资金；原账单若以后收到真实迟到款，会记在原用户 ID 下并由财务人工清退，不会转给重新注册的账户。</p>
@if(!$eligibility['eligible'])
<section><h2>注销前需要处理</h2><ul>@foreach($eligibility['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul></section>
@endif
<form method="post" action="/console/account/close">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}">
<label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label>
<label><input type="checkbox" name="confirm" value="1" required>我确认不可恢复地注销账户，并知悉上述历史业务证据留存与资金处理规则</label>
<button @disabled(!$eligibility['eligible'])>确认注销账户</button>
</form>
<p><a href="/console/account">返回账户安全</a></p>
@endsection
