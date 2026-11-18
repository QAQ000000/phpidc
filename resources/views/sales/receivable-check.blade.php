@extends('sales.layout')
@section('title', '应收历史核对')
@section('content')
<h1>应收历史核对</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<p>财务身份 #{{ $owner->id }} · 原请求 {{ $requestId }}</p>
@if($state === null)<p>本次核验记录已过期，请回到原操作重新提交相同请求。</p>
@else
<p>状态：{{ ['queued' => '等待完整核验', 'running' => '正在核验全部历史', 'ready' => '完整核验完成，请继续原操作', 'stale' => '核验期间证据有变化，请继续原操作重新核验', 'failed' => '完整核验未完成', 'invalid' => '完整核验发现证据异常，请由财务复核'][$state['status']] ?? '待核对' }}</p>
@if(isset($state['reason']))<p role="alert">{{ $state['reason'] }}</p>@endif
@if($state['status'] === 'failed')<form method="post">@csrf<label><input type="checkbox" name="confirm" value="1" required>明确重新核验同一原请求</label><button>重试完整核验</button></form>@endif
@endif
@if($reconciliationRequired)<p role="alert">该身份存在应收证据异常标志，需财务核对完整证据并授权复核。</p>@endif
<p><a href="{{ request()->url() }}">刷新核验状态</a> · <a href="/console/affiliate">返回推广账户</a> · <a href="/admin/finance/receivables?user_id={{ $owner->id }}">返回财务应收</a></p>
<p>完成后使用原请求及原参数继续；系统会重新检查当前证据版本和业务状态。</p>
@endsection
