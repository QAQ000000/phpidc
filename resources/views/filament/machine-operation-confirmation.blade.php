@extends('sales.layout')
@section('title', '机器命令人工核对')
@section('content')
<h1>核对原机器命令</h1>
<p>服务 #{{ $instance->id }} {{ $instance->display_name }}；绑定 {{ $instance->upstream_api_id }}/{{ $instance->upstream_instance_id }}</p>
<p>原请求 <code>{{ $operation['request_id'] }}</code> · {{ $operation['command'] }} · {{ $operation['status'] }}</p>
<p>上游业务状态：{{ $review['observation']['service_state'] }}；当前电源：{{ $review['observation']['power_state'] }}；查询时间 {{ $review['observation']['at'] }}</p>
<p>电源状态不能证明重装、重启、救援或密码操作已经结束。必须在上游核实原任务已终结且没有排队或运行中的命令；没有证据请选择“保持未决”。确认时还会重新查询当前状态。这里不会重放命令。</p>
<p>本次核对凭证有效至 {{ \Illuminate\Support\Carbon::createFromTimestamp($review['expires'])->timezone(config('app.timezone'))->toDateTimeString() }}；超时后请重新查询。</p>
<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/machine-reconcile">@csrf
<input type="hidden" name="resolution_id" value="{{ $resolutionId }}"><input type="hidden" name="operation_request_id" value="{{ $operation['request_id'] }}"><input type="hidden" name="proof" value="{{ $review['proof'] }}">
<input type="hidden" name="decision" value="hold"><button>保持未决，保存本次核对记录</button></form>
<details><summary>已核实任务终结，解除后续操作阻断</summary>
<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/machine-reconcile">@csrf
<input type="hidden" name="resolution_id" value="{{ $resolutionId }}"><input type="hidden" name="operation_request_id" value="{{ $operation['request_id'] }}"><input type="hidden" name="proof" value="{{ $review['proof'] }}"><input type="hidden" name="decision" value="release">
<label>人工核对来源<select name="source"><option value="">尚无证据</option><option value="supplier_panel">上游任务面板</option><option value="supplier_ticket">供应商工单确认</option></select></label>
<label>上游明确结论<select name="conclusion"><option value="">尚未确认</option><option value="completed">任务已完成</option><option value="failed">任务失败且已终结</option><option value="not_executed">确认未执行且已撤销/无排队任务</option></select></label>
<label>上游任务或工单编号<input name="evidence_ref" maxlength="255"></label>
<label>核对到的上游主机 ID<input name="verified_host_id" maxlength="100"></label>
<label>任务终结时间（{{ config('app.timezone') }}）<input type="datetime-local" name="ended_at" step="1"></label>
<label>证据摘要（至少20字，说明原请求对应任务、结果及无待执行命令；勿填写密码、令牌或控制台地址）<textarea name="evidence" minlength="20" maxlength="2000"></textarea></label>
<label><input name="idle_confirm" type="checkbox" value="1">已在上游核实原任务已终结，且没有排队/运行中的相关操作</label>
@if(!$operation['binding_recorded'])<label><input name="legacy_binding_confirm" type="checkbox" value="1">已独立核对历史原请求确实属于上述当前主机；不是根据当前电源状态推断归属</label>@endif
<label>处理原因<input name="reason" required maxlength="1000"></label>
<label><input type="checkbox" name="confirm" value="1" required>确认所填证据；只恢复新请求权限，不重放原命令，不将原结果改为系统验证成功</label>
<button>保存人工核对结果并解除阻断</button></form></details>
<p><a href="/admin/machine-operation-review?instance_id={{ $instance->id }}">返回操作审计；凭证超期请重新查询</a></p>
@endsection
