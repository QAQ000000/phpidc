<x-filament-panels::page>
<p>当前绑定 {{ $instance->upstream_api_id }}/{{ $instance->upstream_instance_id }}；未来续费价 {{ $instance->recurring_amount }} {{ $instance->currency }}；到期 {{ $instance->next_due_at }}。</p>
@if($preview)
@php($snapshot = $preview['snapshot'])
<p>已认证目标商品 #{{ $snapshot['target_product_id'] }}（上游 {{ $snapshot['host']['pid'] }}）、完整实际配置与周期。检查有效至 {{ $preview['expires_at'] }}。</p>
<table><thead><tr><th>项目</th><th>核准值</th></tr></thead><tbody>
<tr><td>原客户续费价（保留）</td><td>{{ $snapshot['original_recurring_amount'] }} CNY</td></tr>
<tr><td>目标商品基础价 + 完整配置价</td><td>{{ $snapshot['base_price'] }} + {{ $snapshot['option_price'] }} = {{ $snapshot['new_recurring_amount'] }} CNY</td></tr>
<tr><td>目录成本 / 当前认证续费成本</td><td>{{ $snapshot['catalog_cost'] }} / {{ $snapshot['renewal_cost'] }} CNY</td></tr>
@foreach($snapshot['resolved_pairs'] as $pair)<tr><td>{{ $pair['label'] }}</td><td>上游组 {{ $pair['upstream_group_id'] }} / 项 {{ $pair['upstream_sub_id'] }}；数量 {{ $pair['qty'] }}；客户单价 {{ $pair['unit_price'] }} CNY</td></tr>@endforeach
</tbody></table>
@if(!$snapshot['price_baseline_matches'])<p role="alert">目标价与原续费价不一致。本次保留原价；升级与续费降价继续等待客户本人预览并确认精确新价。</p>@endif
<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/mapping-review">@csrf
<input type="hidden" name="request_id" value="{{ $preview['request_id'] }}"><input type="hidden" name="review_token" value="{{ $preview['review_token'] }}">
<label>核准原因<input name="reason" required maxlength="255"></label>
<label><input type="checkbox" name="confirm" value="1" required>已核对目标商品、完整配置、周期及价格差异，确认应用未来操作映射</label><button>核准并应用映射</button></form>
@elseif($instance->active_config['mapping_requires_review'] ?? false)
<p>检查会读取供应商认证配置与当前目录。实际配置缺项或目录尚未同步时不能核准。</p>
<form method="post" action="/admin/upstream-instances/{{ $instance->id }}/mapping-review/check">@csrf
<input type="hidden" name="request_id" value="{{ (string) \Illuminate\Support\Str::ulid() }}">
<label>目标本地商品<select name="target_product_id" required>@foreach($products as $product)<option value="{{ $product->id }}">#{{ $product->id }} {{ $product->name }}</option>@endforeach</select></label><button>检查完整映射与计费基线</button></form>
@else<p role="status">当前映射已核准。价格确认状态：{{ ($instance->active_config['price_requires_review'] ?? false) ? '等待客户本人确认' : '已核对' }}。</p>@endif
<h2>不可覆盖的核准历史</h2><ul>@forelse($reviews as $review)<li>#{{ $review->id }} {{ $review->kind }} · 商品 #{{ $review->target_product_id }} · 操作人 #{{ $review->actor_id }} · {{ $review->applied_at }} · {{ $review->reason }}</li>@empty<li>暂无核准记录</li>@endforelse</ul>
</x-filament-panels::page>
