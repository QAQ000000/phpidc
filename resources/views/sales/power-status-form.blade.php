<div data-power-status>
<form method="post" action="/console/instances/{{ $instance->id }}/power-status">@csrf<button type="submit">查看机器电源状态</button></form>
<p data-power-status-result role="status" aria-live="polite">@if(isset($powerStatus))@if($powerStatus['available'])机器电源状态：{{ ['on' => '开机', 'off' => '关机', 'unknown' => '未知'][$powerStatus['state']] }} · 查询时间：{{ $powerStatus['checked_at'] }}@else上游暂未返回可用的机器电源状态，请稍后手动重试。@endif @endif</p>
</div>
<script src="/js/power-status.js" defer></script>
