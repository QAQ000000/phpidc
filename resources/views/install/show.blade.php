@extends('layouts.app')

@section('title', '安装向导')

@section('content')
    <h1>安装向导</h1>

    @if ($errors->any())
        <ul role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('install.store') }}">
        @csrf

        <label>站点地址 <input name="app_url" value="{{ old('app_url', config('app.url')) }}" required></label>
        <label>数据库主机 <input name="db_host" value="{{ old('db_host', '127.0.0.1') }}" required></label>
        <label>数据库端口 <input name="db_port" type="number" value="{{ old('db_port', 3306) }}" required></label>
        <label>数据库名 <input name="db_database" value="{{ old('db_database') }}" required></label>
        <label>数据库用户 <input name="db_username" value="{{ old('db_username') }}" required></label>
        <label>数据库密码 <input name="db_password" type="password"></label>

        <button type="submit">开始安装</button>
    </form>
@endsection
