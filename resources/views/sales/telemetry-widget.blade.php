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
