<div class="settings-plugin-summary">
    <div><strong>{{ count($plugins) }}</strong> 个已安装插件 <span class="settings-summary-divider" aria-hidden="true">/</span> {{ count(array_filter($plugins, fn ($plugin) => $plugin['enabled'])) }} 个已启用</div>
    @if($pluginDefinition['exclusive'])<span class="settings-badge">同类互斥</span>@endif
</div>
<div class="settings-plugin-scroll" role="region" aria-label="{{ $sectionTitle }}列表" tabindex="0">
    <table class="settings-plugin-table">
        <thead><tr><th scope="col">ID</th><th scope="col">插件名称</th><th scope="col">标识</th><th scope="col">描述</th><th scope="col">作者</th><th scope="col">版本</th><th scope="col">状态</th><th scope="col">操作</th></tr></thead>
        <tbody>
        @foreach($plugins as $plugin)
            <tr>
                <td class="settings-plugin-id">{{ $loop->iteration }}</td>
                <td class="settings-plugin-name">{{ $plugin['name'] }}</td>
                <td><code>{{ $plugin['identifier'] }}</code></td>
                <td class="settings-plugin-description">{{ $plugin['description'] }}</td>
                <td>{{ $plugin['author'] }}</td>
                <td>{{ $plugin['version'] }}</td>
                <td><span class="settings-plugin-state {{ $plugin['enabled'] ? 'is-enabled' : '' }}"><span aria-hidden="true"></span>{{ $plugin['enabled'] ? '已启用' : '未启用' }}</span>
                    @if(!$plugin['configured'])<span class="settings-plugin-incomplete">待配置</span>@endif
                </td>
                <td>
                    <div class="settings-plugin-actions">
                        @if($plugin['configuration_url'])<a class="settings-text-link" href="{{ $plugin['configuration_url'] }}" aria-label="{{ $canManage ? '配置' : '查看' }}{{ $plugin['name'] }}">{{ $canManage ? '配置' : '查看' }}</a>@endif
                        @if($canManage && !($plugin['key'] === 'log' && $plugin['enabled']))
                            <form method="post" action="/admin/settings/plugins/{{ $pluginCategory }}/{{ $plugin['key'] }}/status">@csrf
                                <input type="hidden" name="enabled" value="{{ $plugin['enabled'] ? '0' : '1' }}">
                                <button type="submit" class="settings-plugin-toggle {{ $plugin['enabled'] ? 'is-disable' : '' }}" @disabled(!$plugin['enabled'] && !$plugin['configured']) aria-label="{{ $plugin['enabled'] ? '停用' : '启用' }}{{ $plugin['name'] }}">{{ $plugin['enabled'] ? '停用' : '启用' }}</button>
                            </form>
                        @elseif($plugin['key'] === 'log' && $plugin['enabled'])<span class="settings-plugin-fallback">默认通道</span>@endif
                    </div>
                    @if($canManage && !$plugin['enabled'] && !$plugin['configured'])<span class="settings-plugin-incomplete">请先完成配置</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@if(in_array($pluginCategory, ['mail', 'sms'], true))
    <div class="settings-plugin-footer">启用其他插件会切换当前通道。日志模拟仅保留发送记录，不发送真实{{ $pluginCategory === 'mail' ? '邮件' : '短信' }}。@if($pluginCategory === 'sms') 手机绑定启用期间，请先在短信配置中关闭绑定，再停用真实通道。@endif</div>
@endif
