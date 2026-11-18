@extends('sales.layout')
@section('title', '机器状态与监控图表')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p>
<h1>{{ $instance->display_name }} · 机器状态与监控图表</h1>
<p>业务状态：{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$instance->status->value] }}。以下机器运行状态独立查询；图表请求最近 24 小时，时间标签沿用上游返回值。</p>
<form method="post" action="/console/instances/{{ $instance->id }}/telemetry">@csrf<button type="submit">刷新机器状态与图表</button></form>
@foreach($widgets as $widget)
<section data-widget="{{ $widget['key'] }}"><h2>{{ $widget['title'] }}</h2>
@if(!$widget['data']['available'])
<p class="error" role="status">上游暂未返回可用数据，请稍后刷新。</p>
@elseif($widget['type'] === 'machine_status')
<p>机器状态：{{ ['on' => '开机', 'off' => '关机', 'unknown' => '未知'][$widget['data']['state']] }}</p>
@else
@php($chart = \App\Support\TelemetryChart::plot($widget['data']))
@if(!$chart['hasData'])
<p>该时间段暂无采样数据。</p>
@else
<p>单位：{{ $widget['data']['unit'] }} · {{ $chart['start'] }} 至 {{ $chart['end'] }}（上游时间）</p>
<svg viewBox="0 0 800 240" role="img" aria-label="{{ $widget['title'] }}" style="display:block;width:100%;height:auto" data-chart-type="{{ $widget['data']['chart_type'] }}">
<title>{{ $widget['title'] }}（{{ $widget['data']['unit'] }}）</title>
<path d="M40,20 V200 H760" fill="none" stroke="#98a2b3"/>
<text x="4" y="18" font-size="11">{{ $chart['maximum'] }}</text><text x="4" y="214" font-size="11">{{ $chart['minimum'] }}</text>
@foreach($chart['series'] as $series)
<g data-series="{{ $series['label'] }}" fill="{{ $series['color'] }}" stroke="{{ $series['color'] }}">
@if($widget['data']['chart_type'] === 'bar')
@foreach($series['rects'] as $rect)<rect x="{{ $rect['x'] }}" y="{{ $rect['y'] }}" width="{{ $rect['width'] }}" height="{{ $rect['height'] }}" stroke="none"/>@endforeach
@else
@foreach($series['paths'] as $path)
@if($widget['data']['chart_type'] === 'area')<path d="{{ $path['area'] }}" fill-opacity="0.15" stroke="none"/>@endif
<path d="{{ $path['line'] }}" fill="none" stroke-width="1.5"/>
@endforeach
@endif
</g>
@endforeach
</svg>
<ul>@foreach($chart['series'] as $series)<li style="color:{{ $series['color'] }}">{{ $series['label'] }}：{{ $series['count'] }} 个有效采样点；最新值 {{ $series['latest']['value'] ?? '暂无' }} {{ $widget['data']['unit'] }}</li>@endforeach</ul>
@endif
@endif
</section>
@endforeach
@endsection
