@extends('sales.layout')
@section('title', '账户安全')
@section('content')
<x-console.page-header title="账户安全" description="管理邮箱、登录方式、通知订阅与实名认证。" />
<div class="console-grid">
    <x-console.card title="邮箱与密码">

            <dl class="console-kv"><div><dt>邮箱</dt><dd>{{ $user->email }}</dd></div><div><dt>验证状态</dt><dd><x-console.badge :tone="$user->email_verified_at ? 'success' : 'warning'">{{ $user->email_verified_at ? '已验证' : '待验证' }}</x-console.badge></dd></div></dl>
            @if(!$user->email_verified_at)
                @if($mailReady)<form method="post" action="/email/verification-notification">@csrf<button class="console-button">发送邮箱验证链接</button></form>@else<p class="console-muted">账户邮件服务尚未配置，请联系管理员。</p>@endif
            @endif
            <p><a href="/forgot-password">通过邮箱重置密码</a><span class="console-muted">（重置将注销原登录会话）</span></p>

    </x-console.card>
    <x-console.card title="手机号绑定">

            <p>{{ $user->phone ? preg_replace('/(?<=.{4}).(?=.{4})/u', '*', $user->phone) : '未绑定' }} <x-console.badge :tone="$phoneVerified ? 'success' : 'neutral'">{{ $phoneVerified ? '已验证' : '未验证' }}</x-console.badge></p>
            @if(!$phoneReady)<p class="console-muted">真实短信通道尚未接入或配置不完整，暂不能验证或绑定手机号。历史号码不会自动标记已验证。</p>
            @else
                @php($submittedPhone = in_array(old('ui_form'), ['phone-send', 'phone-confirm'], true) && is_string(old('phone')) ? old('phone') : '')
                <form method="post" action="/console/account/phone/send">@csrf<input type="hidden" name="ui_form" value="phone-send">
                    <label>手机号（目前仅支持中国大陆 +86）<input type="tel" name="phone" value="{{ $submittedPhone }}" required pattern="\+861[0-9]{10}" autocomplete="tel"></label><button class="console-button">发送验证码</button>
                </form>
                <form method="post" action="/console/account/phone/confirm">@csrf<input type="hidden" name="ui_form" value="phone-confirm">
                    <label>待验证手机号<input type="tel" name="phone" value="{{ $submittedPhone }}" required autocomplete="tel"></label><label>验证码<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label><button class="console-button is-secondary">验证并绑定</button>
                </form>
            @endif

    </x-console.card>
</div>
<x-console.card title="第三方登录" description="先验证本地邮箱，再输入当前密码绑定。第三方邮箱相同不会自动合并账户。">
    <div class="console-grid">
        @foreach($providers as $provider => $details)
            <div>
                <h3>{{ $details['label'] }}</h3>
                @if($bindings->contains('provider', $provider))
                    <p><x-console.badge tone="success">已绑定</x-console.badge></p>
                    <form method="post" action="/console/account/oauth/{{ $provider }}/unlink">@csrf<label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label><button class="console-button is-secondary">确认解绑</button></form>
                @elseif(!$details['enabled'])<p class="console-muted">尚未配置</p>
                @elseif(!$user->email_verified_at)<p class="console-muted">验证邮箱后可绑定</p>
                @else
                    <form method="post" action="/auth/{{ $provider }}/redirect">@csrf<input type="hidden" name="link" value="1"><label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label><button class="console-button is-secondary">绑定 {{ $details['label'] }}</button></form>
                @endif
            </div>
        @endforeach
    </div>
</x-console.card>
<div class="console-grid">
    <x-console.card title="Telegram 通知">

            <p><x-console.badge :tone="$telegramNotifications ? 'success' : 'neutral'">{{ $telegramNotifications ? '已开启' : '未开启' }}</x-console.badge></p>
            <p class="console-muted">登录绑定与通知订阅分别管理；需允许机器人发送消息。</p>
            @if($bindings->contains('provider', 'telegram') || $telegramNotifications)
                <form method="post" action="/console/account/telegram/notifications">@csrf<input type="hidden" name="enabled" value="{{ $telegramNotifications ? '0' : '1' }}"><label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label><button class="console-button is-secondary">{{ $telegramNotifications ? '关闭 Telegram 通知' : '开启 Telegram 通知' }}</button></form>
            @endif

    </x-console.card>
    <x-console.card title="注销账户">
        <p>结清资金和业务后，可申请注销并匿名化账户联系资料。</p><a class="console-button is-secondary" href="/console/account/close">查看注销条件</a>
    </x-console.card>
</div>
<x-console.card title="实名认证" description="认证完成后请主动查询结果；浏览器返回或提示不会直接改变认证状态。">

        <p><x-console.badge :tone="$user->kyc_verified_at ? 'success' : 'neutral'">{{ $user->kyc_verified_at ? '已认证' : '未认证' }}</x-console.badge></p>
        @if($verification)<p>最近申请 #{{ $verification->id }} · {{ ['pending'=>'待核验','verified'=>'已通过','failed'=>'未通过'][$verification->status] ?? '待人工核对' }}</p>@endif
        @if(!$kycReady)<p class="console-muted">实名认证服务尚未启用或配置不完整，请联系管理员。</p>
        @elseif(!$user->email_verified_at)<p class="console-muted">请先验证邮箱后再发起实名申请。</p>
        @elseif($user->kyc_verified_at)<p class="console-muted">认证已完成，无需重复申请。</p>
        @elseif($verification?->status === 'pending')
            <p>{{ $verification->masked_summary['name'] ?? '' }} · 证件末四位 {{ $verification->masked_summary['id_last4'] ?? '' }}</p>
            @if($verification->external_id)
                <div class="console-actions"><form method="post" action="/console/account/kyc/{{ $verification->id }}/authorize">@csrf<button class="console-button">继续前往认证平台</button></form><form method="post" action="/console/account/kyc/{{ $verification->id }}/refresh">@csrf<button class="console-button is-secondary">查询认证结果</button></form></div>
            @else<p class="console-muted">初始化结果待核对，请联系管理员；请勿重复发起可能收费的申请。</p>@endif
        @else
            <form method="post" action="/console/account/kyc" class="console-form-grid">@csrf
                <p class="console-muted console-span-full">目前支持个人居民身份证认证。姓名、证件号仅用于本次向认证平台提交，不保存明文；本地仅保留脱敏摘要。</p>
                <label>真实姓名<input name="real_name" required maxlength="50" autocomplete="off"></label>
                <label>身份证号<input name="id_number" required minlength="18" maxlength="18" autocomplete="off"></label>
                <label>当前账户密码<input name="current_password" type="password" required autocomplete="current-password"></label>
                <label class="console-span-full"><input name="consent" type="checkbox" value="1" required>同意将上述身份信息提交给实名认证服务商完成核验</label><div class="console-actions console-span-full"><button class="console-button">提交实名申请</button></div>
            </form>
        @endif

</x-console.card>
@endsection
