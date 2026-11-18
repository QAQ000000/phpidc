@extends('sales.layout')
@section('title', '机器状态与监控图表')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p>
<h1>{{ $instance->display_name }} · 机器状态与监控图表</h1>
<p>业务状态：{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}。各项监控独立加载，图表显示最近 24 小时的上游采样；失败项可单独重试。</p>
<p><a href="/console/instances/{{ $instance->id }}/telemetry">刷新全部监控</a></p>
<div data-telemetry>
@if($capabilities['power'])
<section data-telemetry-read="power"><h2>机器电源状态</h2>
<form method="post" action="/console/instances/{{ $instance->id }}/power-status">@csrf<button type="submit">重查电源状态</button></form>
<div data-telemetry-result role="status" aria-live="polite">等待查询…</div>
</section>
@endif
@foreach($capabilities['charts'] as $type => $title)
<section data-telemetry-read="chart"><h2>{{ $title }}</h2>
<form method="post" action="/console/instances/{{ $instance->id }}/telemetry/chart/{{ $type }}">@csrf
<input type="hidden" name="start" value="{{ $window['start'] }}"><input type="hidden" name="end" value="{{ $window['end'] }}">
<button type="submit">重查{{ $title }}</button></form>
<div data-telemetry-result role="status" aria-live="polite">等待查询…</div>
</section>
@endforeach
@if(!$capabilities['power'] && $capabilities['charts'] === [])<p>此服务暂未提供可用的监控项目。</p>@endif
</div>
<noscript><p>浏览器未启用脚本，可一次读取全部监控：</p><form method="post" action="/console/instances/{{ $instance->id }}/telemetry">@csrf<button type="submit">读取全部机器状态与图表</button></form></noscript>
<script src="/js/instance-telemetry.js" defer></script>
@endsection
