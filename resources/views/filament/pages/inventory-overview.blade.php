<x-filament-panels::page>
@include('filament.inventory-feedback')
<div class="inventory-box">
    <p>本地卡池 · 库存与交付概览</p>
    <p class="inventory-note">可用库存为 0 时请补货。达到重试上限的任务仍需操作员确认恢复。</p>
    <form method="get" class="inventory-form"><label>搜索商品<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="商品名称"></label><button class="inventory-button">筛选</button><a class="inventory-link" href="/admin/inventory">重置</a></form>
</div>
<div class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>商品</th><th>可用</th><th class="inventory-secondary">预留 / 已售</th><th>已付待交付</th><th>处理</th></tr></thead><tbody>
@forelse ($products as $product)
<tr><td>{{ $product->name }}<div class="inventory-meta">#{{ $product->id }} · {{ $product->driver_type->value }}</div></td><td>{{ $product->available_count }} @if ($product->available_count === 0)<strong>需补货</strong>@endif</td><td class="inventory-secondary">{{ $product->reserved_count }} / {{ $product->sold_count }}</td><td>{{ $pending->get($product->id, 0) }}</td><td><a class="inventory-link" href="/admin/inventory/cards?product_id={{ $product->id }}">库存</a> · <a class="inventory-link" href="/admin/fulfillment?product_id={{ $product->id }}">交付</a></td></tr>
@empty <tr><td colspan="5">没有匹配的本地卡池商品。</td></tr>@endforelse
</tbody></table>{{ $products->links() }}</div>
</x-filament-panels::page>
