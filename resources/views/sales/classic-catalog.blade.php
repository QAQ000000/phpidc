@extends('sales.layout')
@section('content')
<h1>上游连接 #{{ $accountId }} 商品目录</h1><a href="/admin/upstreams">返回商品导入</a>
@foreach($catalog['products'] ?? [] as $group)<section><h2>{{ $group['name'] ?? $group['groupname'] ?? '商品组' }}</h2><ul>@foreach($group['products'] ?? [] as $product)<li>#{{ $product['id'] }} {{ $product['name'] }}</li>@endforeach</ul></section>@endforeach
@endsection
