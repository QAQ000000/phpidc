@extends('sales.layout')
@section('content')
<h1>上游连接 #{{ $accountId }} 商品目录</h1><a href="/admin/upstreams">返回商品导入</a>
@foreach($catalog['products'] ?? [] as $group)<section><h2>{{ $group['name'] ?? $group['groupname'] ?? '商品组' }}</h2><ul>
@foreach($group['products'] ?? [] as $product)<li>#{{ $product['id'] }} {{ $product['name'] }}
@can('upstream.import')<details><summary>导入此商品</summary><form method="post" action="/admin/upstreams/import">@csrf
<input type="hidden" name="account_id" value="{{ $accountId }}"><input type="hidden" name="pid" value="{{ $product['id'] }}">
<label>初始周期<select name="cycle">@foreach(\App\Enums\BillingCycle::cases() as $cycle)<option value="{{ $cycle->value }}" @selected($cycle->value === 'monthly')>{{ $cycle->label() }}</option>@endforeach</select></label>
<label>币种<input name="currency" value="CNY" pattern="[A-Z]{3}" required></label><label>加价百分比<input name="markup" value="20.00" required></label>
<details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details>
<button>导入并同步此商品</button></form></details>@endcan
</li>@endforeach</ul></section>@endforeach
@endsection
