<x-filament-panels::page>
@include('filament.settings-styles')
<div class="settings-center">
    @include('filament.inventory-feedback')
    @php($canManage = $pluginDefinition ? auth()->user()->can($pluginDefinition['permission']) : ($activeTab === 'payment' ? auth()->user()->can('settings.payment.manage') : ($section && auth()->user()->can($section['permission']))))
    <section class="settings-card" aria-labelledby="settings-section-title">
        <header class="settings-card-header">
            <div>
                <div class="settings-heading-row">
                    <h2 id="settings-section-title">{{ $sectionTitle }}</h2>
                    @if($activeTab !== 'upstreams')<span class="settings-badge">{{ $canManage ? '可编辑' : '只读' }}</span>@endif
                </div>
                <p>{{ $sectionDescription }}</p>
            </div>
            @if($pluginListUrl)<a class="settings-button settings-button-secondary" href="{{ $pluginListUrl }}">返回插件列表</a>@endif
            @if($activeTab === 'site')<a class="settings-text-link" href="/" target="_blank" rel="noopener">预览前台 <span aria-hidden="true">↗</span></a>@endif
        </header>
        @if($pluginCategory)
            @include('filament.settings-plugins')
        @elseif($activeTab === 'payment')
            @if($canManage)<form method="post" action="/admin/settings/payment" autocomplete="off">@csrf
                @if($pluginContext)<input type="hidden" name="plugin_context" value="{{ $pluginContext['definition']['key'] }}">@endif
            @endif
            <div class="settings-card-body">
                <section class="settings-field-group" aria-labelledby="payment-merchant-heading">
                    <h3 id="payment-merchant-heading">商户信息</h3>
                    <dl class="settings-fields">
                        <div class="settings-field">
                            <dt><label for="settings-payment-url">API 接口地址</label></dt>
                            <dd class="settings-control">
                                @if($canManage)<input id="settings-payment-url" type="url" name="url" value="{{ $payment['url'] }}" maxlength="2048" placeholder="https://pay.example.com" required aria-describedby="settings-payment-url-help">
                                @else<span class="settings-read-value">{{ $payment['url'] ?: '未配置' }}</span>@endif
                                <p class="settings-field-help" id="settings-payment-url-help">填写易支付平台提供的接口根地址。</p>
                            </dd>
                        </div>
                        <div class="settings-field">
                            <dt><label for="settings-payment-pid">商户 PID</label></dt>
                            <dd class="settings-control">@if($canManage)<input id="settings-payment-pid" name="pid" value="{{ $payment['pid'] }}" maxlength="255" placeholder="填写商户编号" required>@else<span class="settings-read-value">{{ $payment['pid'] ?: '未配置' }}</span>@endif</dd>
                        </div>
                        <div class="settings-field">
                            <dt><label for="settings-payment-key">商户密钥</label>@if($canManage)<span class="settings-field-badge">{{ $payment['has_key'] ? '已配置' : '未配置' }}</span>@endif</dt>
                            <dd class="settings-control">
                                @if($canManage)<input id="settings-payment-key" type="password" name="epay_key" value="" maxlength="4096" autocomplete="new-password" placeholder="{{ $payment['has_key'] ? '留空保留现有密钥' : '输入商户密钥' }}" aria-describedby="settings-payment-key-help">
                                @else<span class="settings-read-value">{{ $payment['has_key'] ? '已配置' : '未配置' }}</span>@endif
                                <p class="settings-field-help" id="settings-payment-key-help">已保存的密钥不显示，填写新值时才会替换。</p>
                            </dd>
                        </div>
                    </dl>
                </section>
                <section class="settings-field-group" aria-labelledby="payment-channels-heading">
                    <h3 id="payment-channels-heading">支付渠道</h3>
                    <dl class="settings-fields">
                        @foreach(['alipay' => '支付宝', 'wechat' => '微信支付'] as $key => $label)
                            <div class="settings-field">
                                <dt><label for="settings-payment-{{ $key }}">{{ $label }}</label></dt>
                                <dd class="settings-control">
                                    @if($canManage)
                                        <input type="hidden" name="{{ $key }}" value="0">
                                        <label class="settings-switch" for="settings-payment-{{ $key }}">
                                            <input id="settings-payment-{{ $key }}" type="checkbox" role="switch" name="{{ $key }}" value="1" @checked($payment[$key])>
                                            <span class="settings-switch-track" aria-hidden="true"></span>
                                            <span class="settings-switch-on">已启用</span><span class="settings-switch-off">已停用</span>
                                        </label>
                                    @else<span class="settings-read-value">{{ $payment[$key] ? '启用' : '停用' }}</span>@endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="settings-inline-note">停用渠道后不再发起新支付，已经发起的付款仍可正常入账。更换商户不会影响旧支付的核验。</p>
                </section>
                @if($canManage)<div class="settings-optional-note">@include('filament.settings-note')</div>@endif
            </div>
            @if($canManage)@include('filament.settings-savebar')</form>@endif
        @elseif($activeTab === 'upstreams')
            <div class="settings-card-body">
                <div class="settings-connection">
                    <div class="settings-connection-icon" aria-hidden="true">↗</div>
                    <div><h3>集中管理上游连接</h3><p>在上游管理中配置经典魔方和 Sub2API 的账户、接口地址与启停状态。</p></div>
                </div>
                <p class="settings-inline-note">Sub2API 更换服务地址时请新建连接，旧连接保留用于处理原订单。</p>
                <a class="settings-button settings-button-primary" href="/admin/upstreams">管理上游连接 <span aria-hidden="true">→</span></a>
            </div>
        @elseif($section)
            @if($canManage)<form method="post" action="{{ $saveAction }}" autocomplete="off">@csrf
                @if($pluginContext)<input type="hidden" name="plugin_context" value="{{ $pluginContext['definition']['key'] }}">@endif
            @endif
            <div class="settings-card-body">
                @if($pluginContext)<p class="settings-plugin-config-note">此处保存接口参数，启停请在插件列表操作。@if($activeTab === 'telegram') 机器人凭据也供通知使用，登录开关只控制第三方登录。@endif</p>@endif
                @foreach($fieldGroups as $group)
                    <section class="settings-field-group" aria-labelledby="settings-group-{{ $loop->index }}">
                        <h3 id="settings-group-{{ $loop->index }}">{{ $group['title'] }}</h3>
                        <dl class="settings-fields">
                            @foreach($group['keys'] as $key)
                                @include('filament.settings-field', ['field' => $section['fields'][$key], 'fieldKey' => $key, 'editable' => $canManage])
                            @endforeach
                        </dl>
                    </section>
                @endforeach
                @if(in_array($activeTab, ['github', 'google', 'qq', 'wechat'], true))
                    <section class="settings-field-group" aria-labelledby="settings-callback-heading">
                        <h3 id="settings-callback-heading">授权回调</h3>
                        <dl class="settings-fields"><div class="settings-field">
                            <dt><label for="settings-callback-url">回调地址</label></dt>
                            <dd class="settings-control"><input id="settings-callback-url" type="text" value="{{ rtrim(config('app.url'), '/') }}/auth/{{ $activeTab }}/callback" readonly x-on:click="$el.select()" aria-describedby="settings-callback-help"><p id="settings-callback-help" class="settings-field-help">将此地址填写到开放平台的授权回调设置中，点击可选中复制。</p></dd>
                        </div></dl>
                    </section>
                @endif
                @if($canManage)<div class="settings-optional-note">@include('filament.settings-note')</div>@endif
            </div>
            @if($canManage)@include('filament.settings-savebar')</form>@endif
            @if($canManage && in_array($activeTab, ['mail', 'sms', 'telegram'], true))
                <section class="settings-test" aria-labelledby="settings-test-heading">
                    <div>
                        <h3 id="settings-test-heading">{{ $activeTab === 'telegram' ? '检查连接' : '测试当前通道' }}
                            @if(($activeTab === 'mail' && $values['mail.transport']['value'] === 'log') || ($activeTab === 'sms' && $values['sms.driver']['value'] === 'log'))<span class="settings-badge">日志模拟</span>@endif
                        </h3>
                        <p>@if($activeTab === 'telegram')使用已保存的配置读取机器人身份，不发送消息。
                            @elseif(($activeTab === 'mail' && $values['mail.transport']['value'] === 'log') || ($activeTab === 'sms' && $values['sms.driver']['value'] === 'log'))当前使用日志模拟。要测试真实发送，请先保存配置并在插件列表启用此插件。
                            @else先保存配置，再向本人已验证的{{ $activeTab === 'mail' ? '邮箱' : '手机号' }}发送测试。@endif</p>
                    </div>
                    <form method="post" action="/admin/settings/test/{{ $activeTab }}">@csrf
                        @if($pluginContext)<input type="hidden" name="plugin_context" value="{{ $pluginContext['definition']['key'] }}">@endif
                        <button class="settings-button settings-button-secondary">{{ $activeTab === 'telegram' ? '检查 Telegram 连接' : '发送测试'.($activeTab === 'mail' ? '邮件' : '短信') }}</button></form>
                </section>
            @endif
        @endif
    </section>
</div>
</x-filament-panels::page>
