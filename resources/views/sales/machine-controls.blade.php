@extends('sales.layout')
@section('title', '机器管理')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p>
<h1>机器管理 · {{ $instance->display_name }}</h1>
<p>仅显示上游当前提供的操作。操作受理后请通过监控核对结果；连接超时需人工核对，请勿重复执行。</p>
@php($labels = ['on' => '开机', 'off' => '关机', 'reboot' => '重启', 'hard_off' => '硬关机', 'hard_reboot' => '硬重启', 'reinstall' => '重装系统', 'rescueSystem' => '救援系统', 'vnc' => '打开 VNC 控制台'])
@foreach($controls['commands'] as $command)
<section><h2>{{ $labels[$command] }}</h2>
<form method="post" action="/console/instances/{{ $instance->id }}/machine/command" @if($command === 'vnc') target="_blank" rel="noopener noreferrer" @endif>
@csrf
<input type="hidden" name="command" value="{{ $command }}">
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
@if($command === 'reinstall')
<p class="error">重装会覆盖系统盘。请先备份数据，并确认系统与版本。</p>
<div data-os-selector>
<label>系统<select name="os_group" required><option value="">请选择系统</option>@foreach($controls['groups'] as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach</select></label>
<label>版本<select name="os" required><option value="">请先选择系统</option>@foreach($controls['systems'] as $os)<option value="{{ $os['id'] }}" data-group="{{ $os['group'] }}" hidden disabled>{{ $os['name'] }}</option>@endforeach</select></label>
@if($controls['port'])<label>登录端口<input name="port" type="number" min="1" max="65535" value="22" required></label>@endif
</div>
@if($controls['format_data_disk'])<label><input type="checkbox" name="format_data_disk" value="1">同时格式化数据盘（永久删除数据）</label>@endif
<label><input type="checkbox" name="backup_confirm" value="1" required>已完成备份，确认覆盖所选系统</label>
@elseif($command === 'rescueSystem')
<p class="error">进入救援系统可能中断业务。退出救援请联系管理员使用上游面板处理。</p>
<label>救援系统<select name="system" required><option value="2">Linux</option><option value="1">Windows</option></select></label>
<label>临时密码<input type="password" name="temp_pass" minlength="6" maxlength="20" autocomplete="new-password" required></label>
<p>临时密码需满足商品与上游密码规则（长度至少 {{ $controls['password_rule']['len_num'] ?? 6 }}，最多 20 字节）。请妥善保存。</p>
<label><input type="checkbox" name="backup_confirm" value="1" required>已完成备份，确认进入救援系统并中断业务</label>
@elseif(in_array($command, ['off', 'reboot', 'hard_off', 'hard_reboot'], true))
<p class="error">此操作会中断业务；硬关机或硬重启可能损坏未保存的数据。</p>
@elseif($command === 'vnc')
<p>本站加载控制台页面，浏览器直接连接远端 WSS；控制台流量不经过本站中转。</p>
@endif
@if(!in_array($command, ['on', 'vnc'], true))
<details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details>
@if(!in_array($command, ['reinstall', 'rescueSystem'], true))<label><input type="checkbox" name="confirm" value="1" required>确认{{ $labels[$command] }}并中断业务</label>@endif
@endif
<button type="submit" @if($command === 'reinstall' && empty($controls['systems'])) disabled @endif>{{ $labels[$command] }}</button>
</form></section>
@endforeach
<script src="/js/machine-controls.js" defer></script>
@endsection
