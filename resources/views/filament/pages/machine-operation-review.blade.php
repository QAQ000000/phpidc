<x-filament-panels::page>
@include('filament.inventory-feedback')
<section class="inventory-box"><h2>#{{ $instance->id }} {{ $instance->display_name }}</h2>
<p>当前绑定 {{ $instance->upstream_api_id }}/{{ $instance->upstream_instance_id }} · {{ $instance->status->value }}</p>
<p><a class="inventory-link" href="/admin/upstream-instances">返回上游服务</a></p>
@if($operation)
<p>原请求 <code>{{ $operation['request_id'] ?? '缺失' }}</code> · 命令 {{ $operation['command'] ?? '缺失' }} · 原结果 {{ $operation['status'] ?? '缺失' }}</p>
<p>{{ $operation['blocked'] ? '后续机器命令被阻止，须先核对。' : '当前没有未决阻断；原请求仍不能重复提交。' }}</p>
@if($operation['binding_recorded'])<p>原请求绑定 {{ $operation['binding']['upstream_api_id'] }}/{{ $operation['binding']['upstream_instance_id'] }}；{{ $operation['binding_matches'] ? '与当前绑定一致' : '与当前绑定不一致，须核对旧绑定' }}</p>@endif
@if(isset($operation['requested_at']))<p>原提交时间 {{ $operation['requested_at'] }}</p>@endif
@foreach($operation['parameters'] ?? [] as $name => $value)@if(is_scalar($value))<p>{{ $name }}：{{ $value }}</p>@endif@endforeach
@if(!$operation['binding_recorded'])<p>历史请求未保存绑定，释放前必须通过上游任务或工单独立核对原主机归属；无法确认时保留未决。</p>@endif
@can('upstream.lifecycle')
@if($operation['blocked'] && isset($operation['request_id']))
<p>请求提交后五分钟内或请求仍在执行时不能处理。这里只查询，不重放原命令。</p>
<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/machine-review" class="inventory-form">@csrf
<input type="hidden" name="operation_request_id" value="{{ $operation['request_id'] }}">
<label>核对原因<input name="reason" required maxlength="1000"></label><button class="inventory-button">查询上游现状并核对</button>
</form>
@endif
@endcan
@else<p>当前绑定没有机器命令记录。旧绑定归档仍保留在下方审计中。</p>@endif
</section>
<section class="inventory-box"><h2>最近30条机器操作审计</h2>
@forelse($history as $record)
<details><summary>#{{ $record['id'] }} · {{ $record['action'] }} · {{ $record['at'] }}</summary>
<p>操作员 #{{ $record['actor_id'] ?? '—' }}；原因：{{ $record['reason'] }}</p>
<p>原请求：{{ $record['after']['operation_request_id'] ?? $record['before']['request_id'] ?? $record['after']['request_id'] ?? '—' }}</p>
@if(isset($record['after']['released']))<p>{{ $record['after']['released'] ? '人工核对后允许新请求；不代表系统证明原命令成功' : '继续保持未决' }} · 结论 {{ $record['after']['conclusion'] ?? '未决' }}</p>@endif
@if(isset($record['after']['observation']))<p>查询时间 {{ $record['after']['observation']['at'] }}；业务状态 {{ $record['after']['observation']['service_state'] }}；电源 {{ $record['after']['observation']['power_state'] }}</p>@endif
@if(isset($record['after']['evidence']))<p>证据来源 {{ $record['after']['evidence']['source'] ?? '—' }} · 任务/工单 {{ $record['after']['evidence']['evidence_ref'] ?? '—' }}；主机 {{ $record['after']['evidence']['verified_host_id'] ?? '—' }}；终结时间 {{ $record['after']['evidence']['ended_at'] ?? '—' }}</p><p>{{ $record['after']['evidence']['evidence'] ?? '' }}</p>@endif
@if($record['action'] === 'instance.machine.archived')<p>旧绑定 {{ $record['before']['binding']['upstream_api_id'] ?? '—' }}/{{ $record['before']['binding']['upstream_instance_id'] ?? '—' }} → 新绑定 {{ $record['after']['target_api_id'] }}/{{ $record['after']['target_host_id'] }}。旧操作{{ $record['after']['requires_supplier_review'] ? '仍未决，需在旧供应商核对' : '已保留审计' }}。</p>@endif
</details>
@empty<p>没有机器操作审计。</p>@endforelse
</section>
</x-filament-panels::page>
