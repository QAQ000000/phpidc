@extends('layouts.app')

@section('title', '安装向导')

@section('content')
    <style>
        .installer { max-width: 52rem; margin: 2rem auto; padding: 1rem; font-family: system-ui, sans-serif; line-height: 1.65; overflow-wrap: anywhere; }
        .installer label { display: block; margin: 1rem 0; }
        .installer input { display: block; box-sizing: border-box; width: 100%; max-width: 34rem; padding: .65rem; font: inherit; }
        .installer button { padding: .65rem 1.5rem; font: inherit; cursor: pointer; }
        .installer [role="alert"] { color: #a01818; }
    </style>
    <div class="installer">
    <h1>安装向导</h1>
    <p>请先在服务器配置 .env、生成并备份 APP_KEY，然后运行 php artisan app:install-token。此页面只初始化空库，不修改服务器连接配置。</p>
    <p>生产要求 PHP 8.3+、MySQL 8.0+、Redis、HTTPS。初始管理员仅授予权限管理角色，业务操作角色须另行分配。</p>
    <ul>
        @foreach ($checks as $name => $ok)
            <li>{{ $ok ? '通过' : '未通过' }}：{{ $name }}</li>
        @endforeach
    </ul>

    @if ($errors->any())
        <ul role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('install.store') }}">
        @csrf

        <label>安装令牌 <input name="install_token" type="password" autocomplete="off" required minlength="64" maxlength="64"></label>
        <label>管理员名称 <input name="admin_name" value="{{ old('admin_name') }}" required maxlength="255"></label>
        <label>管理员邮箱 <input name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="255"></label>
        <label>管理员密码（至少 12 位，含大小写、数字、符号）<input name="admin_password" type="password" autocomplete="new-password" required minlength="12" maxlength="255"></label>
        <label>确认密码 <input name="admin_password_confirmation" type="password" autocomplete="new-password" required minlength="12" maxlength="255"></label>
        <label>安装原因 <input name="reason" value="{{ old('reason') }}" required maxlength="255"></label>

        <button type="submit">开始安装</button>
    </form>
    </div>
@endsection
