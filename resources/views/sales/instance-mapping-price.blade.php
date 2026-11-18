@extends('sales.layout')
@section('title', '确认换绑后的未来续费价格')
@section('content')
<p><a href="/console/instances/{{ $instance->id }}">返回服务详情</a></p><h1>确认换绑后的未来续费价格</h1>
<p>服务：{{ $instance->display_name }}（#{{ $instance->id }}）。当前续费价 {{ $instance->recurring_amount }} {{ $instance->currency }}，到期 {{ $instance->next_due_at }}。</p>
@if($instance->active_config['mapping_requires_review'] ?? false)
<p>配置映射尚未完成，请联系管理员核对目标商品与完整配置。</p>
@elseif(!($instance->active_config['price_requires_review'] ?? false))
<p role="status">当前未来续费价格已核对，可以返回服务详情继续操作。</p>
@elseif($preview)
@php($snapshot = $preview['snapshot'])
@php($increase = \App\Support\Money::gte($snapshot['new_recurring_amount'], $snapshot['original_recurring_amount']))
@php($difference = $increase ? \App\Support\Money::sub($snapshot['new_recurring_amount'], $snapshot['original_recurring_amount']) : \App\Support\Money::sub($snapshot['original_recurring_amount'], $snapshot['new_recurring_amount']))
<p>周期：{{ $instance->billing_cycle->label() }}。已按目标商品和全部实际配置计算；有效至 {{ $preview['expires_at'] }}。</p>
<table><thead><tr><th>项目</th><th>CNY</th></tr></thead><tbody>
<tr><td>原未来续费价格</td><td>{{ $snapshot['original_recurring_amount'] }}</td></tr>
<tr><td>商品基础价</td><td>{{ $snapshot['base_price'] }}</td></tr>
@foreach($snapshot['resolved_pairs'] as $pair)<tr><td>{{ $pair['label'] }}：{{ $pair['unit_price'] }} × {{ $pair['qty'] }}</td><td>{{ \App\Support\Money::mul($pair['unit_price'], $pair['qty']) }}</td></tr>@endforeach
<tr><td><strong>确认后的未来续费价格</strong></td><td><strong>{{ $snapshot['new_recurring_amount'] }}</strong></td></tr>
<tr><td>{{ $increase ? '增加' : '减少' }}</td><td>{{ $difference }}</td></tr>
<tr><td>供应商目录成本 / 当前续费成本（独立核对项）</td><td>{{ $snapshot['catalog_cost'] }} / {{ $snapshot['renewal_cost'] }}</td></tr>
</tbody></table>
<p>确认仅应用未来续费价格，不产生本期扣款或退款。原账单、到期日和远程配置保留。</p>
<form method="post" action="/console/instances/{{ $instance->id }}/mapping-price">@csrf
<input type="hidden" name="request_id" value="{{ $preview['request_id'] }}"><input type="hidden" name="review_token" value="{{ $preview['review_token'] }}">
<label>当前密码<input type="password" name="current_password" required autocomplete="current-password"></label>
<label><input type="checkbox" name="confirm" value="1" required>我已核对以上完整配置和价格差异，同意未来每个该周期按 {{ $snapshot['new_recurring_amount'] }} CNY 续费</label><button>确认未来续费价格</button></form>
@else
<p>映射已核对，但原价与目标商品完整配置的价格基线不一致。请先预览准确新价与差异，再由本人确认。</p>
<form method="post" action="/console/instances/{{ $instance->id }}/mapping-price/preview">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}"><button>预览完整配置与未来续费价格</button></form>
@endif
@endsection
