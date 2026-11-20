<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-instance.css?v=20261120-c4">
@php
    $labels = ['on' => '开机', 'off' => '关机', 'reboot' => '重启', 'hard_off' => '硬关机', 'hard_reboot' => '硬重启', 'reinstall' => '重装系统', 'rescueSystem' => '救援系统', 'vnc' => '打开 VNC 控制台'];
    $workspaceQuery = \App\Support\AdminWorkspaceNavigation::query(auth()->user(), 'instance', request()->query());
    $workspaceAction = fn (string $path): string => $path.($workspaceQuery === [] ? '' : '?'.http_build_query($workspaceQuery));
    $workspaceStatus = match($instance->status) { \App\Enums\InstanceStatus::PENDING => '待开通', \App\Enums\InstanceStatus::ACTIVE => '运行中', \App\Enums\InstanceStatus::SUSPENDED => '已暂停', \App\Enums\InstanceStatus::CANCELLED => '已取消', \App\Enums\InstanceStatus::FRAUD => '异常', \App\Enums\InstanceStatus::DELETED => '已删除', \App\Enums\InstanceStatus::PROVISION_FAILED => '开通失败' };
@endphp
<div class="admin-ui admin-instance-workbench" x-data>
    <x-admin.workspace-header :name="$instance->display_name" :identity="'实例 #'.$instance->id.' · 机器管理'" :status="$workspaceStatus" :tone="$instance->status === \App\Enums\InstanceStatus::ACTIVE ? 'success' : 'warning'" :description="'以当前管理员身份操作客户 #'.$instance->user_id.' 的服务；受理后仍需核对实际结果。'" :return-url="\App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, $workspaceQuery)" return-label="返回实例工作台" />
    <x-admin.card title="机器管理" description="仅显示上游本次提供的能力。操作受理后仍需核对结果，超时或结果未知时请核对原请求。">
        <div class="admin-ui-card-body">
            <div class="admin-instance-actions">
                <form method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/power-status') }}">@csrf<button class="admin-ui-button">查询电源状态</button></form>
                <a class="admin-ui-link" href="/admin/machine-operation-review?instance_id={{ $instance->id }}">机器命令核对与历史</a>
            </div>
            <div class="admin-machine-grid">
            @forelse($controls['commands'] as $command)
                @if(isset($labels[$command]))
                <section class="admin-machine-command"><h3>{{ $labels[$command] }}</h3>
                @if(in_array($command, ['on', 'vnc'], true))
                    <p class="admin-ui-meta">{{ $command === 'vnc' ? '临时控制台链接仅供当前管理员使用，五分钟内有效。' : '提交开机请求后可查询电源状态。' }}</p>
                    <form method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/machine/command') }}" @if($command === 'vnc') target="_blank" rel="noopener noreferrer" @endif>@csrf
                        <input type="hidden" name="command" value="{{ $command }}"><input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                        <button type="submit" class="admin-ui-button admin-ui-button-primary">{{ $labels[$command] }}</button>
                    </form>
                @else
                    <p class="admin-ui-meta">{{ in_array($command, ['reinstall', 'rescueSystem'], true) ? '操作前请备份并核对系统参数。' : '此操作会中断业务，请确认后提交。' }}</p>
                    <button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="machine-{{ $command }}" x-on:click="document.getElementById('machine-{{ $command }}').showModal()">{{ $labels[$command] }}</button>
                @endif
                </section>
                @endif
            @empty<p class="admin-ui-meta">上游本次未提供可用机器操作。</p>@endforelse
            </div>
        </div>
    </x-admin.card>
    @foreach($controls['commands'] as $command)
    @if(isset($labels[$command]) && !in_array($command, ['on', 'vnc'], true))
        <x-admin.dialog :id="'machine-'.$command" :title="$labels[$command]" description="请求以当前管理员身份提交；受理不代表操作完成。">
            <form method="post" action="{{ $workspaceAction('/admin/upstream-instances/'.$instance->id.'/machine/command') }}" class="admin-instance-form">@csrf
                <input type="hidden" name="command" value="{{ $command }}"><input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
                @if($command === 'reinstall')
                    <p class="admin-instance-notice">重装会覆盖系统盘，请确认已完成备份。</p>
                    <div data-os-selector class="admin-instance-form">
                        <label>系统<select name="os_group" required><option value="">请选择系统</option>@foreach($controls['groups'] as $group)<option value="{{ $group['id'] }}">{{ $group['name'] }}</option>@endforeach</select></label>
                        <label>版本<select name="os" required><option value="">请先选择系统</option>@foreach($controls['systems'] as $os)<option value="{{ $os['id'] }}" data-group="{{ $os['group'] }}" hidden disabled>{{ $os['name'] }}</option>@endforeach</select></label>
                        @if($controls['port'])<label>登录端口<input name="port" type="number" min="1" max="65535" value="22" required></label>@endif
                    </div>
                    @if($controls['format_data_disk'])<label><span><input type="checkbox" name="format_data_disk" value="1"> 同时格式化数据盘（永久删除数据）</span></label>@endif
                    <label><span><input type="checkbox" name="backup_confirm" value="1" required> 已完成备份，确认覆盖所选系统。</span></label>
                @elseif($command === 'rescueSystem')
                    <p class="admin-instance-notice">进入救援可能中断业务，退出救援请使用上游面板处理。</p>
                    <label>救援系统<select name="system" required><option value="2">Linux</option><option value="1">Windows</option></select></label>
                    <label>临时密码<input type="password" name="temp_pass" minlength="6" maxlength="20" autocomplete="new-password" required></label>
                    <p class="admin-ui-meta">密码需满足上游与商品规则（长度至少 {{ $controls['password_rule']['len_num'] ?? 6 }}，最多 20 字节），请自行妥善保存。</p>
                    <label><span><input type="checkbox" name="backup_confirm" value="1" required> 已完成备份，确认进入救援并中断业务。</span></label>
                @else
                    <p class="admin-instance-notice">此操作会中断业务；硬关机或硬重启可能损坏未保存的数据。</p>
                    <label><span><input type="checkbox" name="confirm" value="1" required> 确认{{ $labels[$command] }}并中断业务。</span></label>
                @endif
                <details><summary>添加备注（可选）</summary><label>备注<input name="reason" maxlength="255" autocomplete="off"></label></details>
                <div class="admin-instance-form-actions"><button type="submit" class="admin-ui-button admin-ui-button-primary" @if($command === 'reinstall' && empty($controls['systems'])) disabled @endif>确认{{ $labels[$command] }}</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endif
    @endforeach
</div>
<script src="/js/machine-controls.js" defer></script>
</x-filament-panels::page>
