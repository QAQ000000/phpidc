<x-filament-panels::page>
@include('filament.inventory-feedback')
<nav class="settings-tabs" aria-label="系统配置分组">
@foreach($tabs as $key => $label)<a href="/admin/settings?tab={{ $key }}" @if($activeTab === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>
@if($activeTab === 'payment')
<section class="inventory-box"><h2>易支付接口</h2><p class="inventory-note">修改商户后，已发起的支付仍按原商户快照核验。关闭渠道只停止新的支付发起。</p>
@can('settings.payment.manage')
<form method="post" action="/admin/settings/payment" class="inventory-form settings-form" autocomplete="off">@csrf
<label>API 接口地址<input type="url" name="url" value="{{ $payment['url'] }}" maxlength="2048" required></label>
<label>商户 PID<input name="pid" value="{{ $payment['pid'] }}" maxlength="255" required></label>
<label>商户密钥<input type="password" name="epay_key" value="" maxlength="4096" autocomplete="new-password" placeholder="{{ $payment['has_key'] ? '已配置，留空保留' : '尚未配置' }}"></label>
<input type="hidden" name="alipay" value="0"><label><input type="checkbox" name="alipay" value="1" @checked($payment['alipay'])>启用支付宝</label>
<input type="hidden" name="wechat" value="0"><label><input type="checkbox" name="wechat" value="1" @checked($payment['wechat'])>启用微信支付</label>
@include('filament.settings-note')
<button class="inventory-button">保存支付配置</button></form>
@else<dl><dt>API 接口地址</dt><dd>{{ $payment['url'] ?: '未配置' }}</dd><dt>商户 PID</dt><dd>{{ $payment['pid'] ?: '未配置' }}</dd><dt>商户密钥</dt><dd>{{ $payment['has_key'] ? '已配置' : '未配置' }}</dd><dt>支付宝 / 微信</dt><dd>{{ $payment['alipay'] ? '启用' : '停用' }} / {{ $payment['wechat'] ? '启用' : '停用' }}</dd></dl>@endcan
</section>
@elseif($activeTab === 'upstreams')
<section class="inventory-box"><h2>上游连接</h2><p>经典魔方与 Sub2API 连接共用已有管理入口。Sub2API 更换服务地址时请新建连接，保留旧连接处理原订单。</p><a class="inventory-button" href="/admin/upstreams">管理上游连接</a></section>
@elseif($section)
<section class="inventory-box"><h2>{{ $section['label'] }}</h2>
@if($activeTab === 'mail')<p class="inventory-note">SMTP 465 使用隐式 TLS，587 按服务器支持协商 STARTTLS。日志模式仅模拟投递。</p>@endif
@if($activeTab === 'sms')<p class="inventory-note">短信模板使用 JSON 对象，每项包含 id 和 content。测试短信需配置 system.settings_test 模板，仅支持 message 变量。</p>@endif
@if(in_array($activeTab, ['github','google','qq','wechat'], true))<p class="inventory-note">授权回调地址：{{ rtrim(config('app.url'), '/') }}/auth/{{ $activeTab }}/callback</p>@endif
@can($section['permission'])
<form method="post" action="/admin/settings/identity/{{ $activeTab }}" class="inventory-form settings-form" autocomplete="off">@csrf
@foreach($section['fields'] as $key => $field)
<label>{{ $field['label'] }}
@if($field['type'] === 'checkbox')
<input type="hidden" name="settings[{{ $key }}]" value="0"><input type="checkbox" name="settings[{{ $key }}]" value="1" @checked(in_array($values[$key]['value'], ['1','true'], true))>
@elseif($field['type'] === 'select')
<select name="settings[{{ $key }}]">@foreach($field['options'] as $option => $label)<option value="{{ $option }}" @selected($values[$key]['value'] === (string) $option)>{{ $label }}</option>@endforeach</select>
@elseif($field['type'] === 'textarea')
<textarea name="settings[{{ $key }}]" rows="8">{{ $values[$key]['value'] }}</textarea>
@elseif($field['secret'] ?? false)
<input type="password" name="settings[{{ $key }}]" value="" autocomplete="new-password" placeholder="{{ $values[$key]['configured'] ? '已配置，留空保留' : '尚未配置' }}">
@else
<input type="{{ $field['type'] }}" name="settings[{{ $key }}]" value="{{ $values[$key]['value'] }}" @if($key === 'mail.port') min="1" max="65535" @endif>
@endif
</label>
@endforeach
@include('filament.settings-note')
<button class="inventory-button">保存{{ $section['label'] }}配置</button></form>
@else<dl>@foreach($section['fields'] as $key => $field)<dt>{{ $field['label'] }}</dt><dd>{{ ($field['secret'] ?? false) ? ($values[$key]['configured'] ? '已配置' : '未配置') : ($values[$key]['value'] !== '' ? $values[$key]['value'] : '未配置') }}</dd>@endforeach</dl>@endcan
</section>
@if(in_array($activeTab, ['mail','sms','telegram'], true))
@can($section['permission'])<section class="inventory-box"><h2>测试已保存配置</h2>
<p class="inventory-note">{{ $activeTab === 'telegram' ? '只读取机器人身份，不发送 Telegram 消息。' : '仅发送至本人已验证的'.($activeTab === 'mail' ? '邮箱' : '手机号').'，不会自动重试。' }}</p>
<form method="post" action="/admin/settings/test/{{ $activeTab }}">@csrf<button class="inventory-button">{{ $activeTab === 'telegram' ? '检查 Telegram 连接' : '发送测试'.($activeTab === 'mail' ? '邮件' : '短信') }}</button></form>
</section>@endcan
@endif
@endif
<style>.settings-tabs{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}.settings-tabs a{padding:8px 14px;border:1px solid #9ca3af66;border-radius:8px}.settings-tabs a[aria-current=page]{background:#4f46e5;color:white}.settings-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));align-items:start}.settings-form label{min-width:0}.settings-form input:not([type=checkbox]),.settings-form select,.settings-form textarea{width:100%}.settings-form details,.settings-form button{grid-column:1/-1}.settings-form button{justify-self:start}@media(max-width:700px){.settings-form{grid-template-columns:1fr}}</style>
</x-filament-panels::page>
