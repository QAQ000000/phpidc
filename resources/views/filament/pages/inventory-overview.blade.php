<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>本地卡池的库存与交付概览。可用库存为 0 时请补货，达到重试上限的任务仍需操作员确认恢复。</p></div></header>
    <x-admin.card title="库存概览">
        <x-admin.filters action="/admin/inventory"><label>搜索商品<input name="q" value="{{ request('q') }}" maxlength="100" placeholder="商品名称"></label></x-admin.filters>
        <x-admin.table label="本地卡池库存"><x-slot:head><tr><th scope="col">商品</th><th scope="col" class="admin-ui-number">可用库存</th><th scope="col" class="admin-ui-number">预留 / 已售</th><th scope="col" class="admin-ui-number">已付待交付</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($products as $product)<tr>
                <td class="admin-ui-product-name">{{ $product->name }}<span class="admin-ui-meta">#{{ $product->id }} · {{ $product->driver_type->value }}</span></td>
                <td class="admin-ui-number">{{ $product->available_count }} @if($product->available_count === 0)<x-admin.badge tone="warning">需补货</x-admin.badge>@endif</td>
                <td class="admin-ui-number">{{ $product->reserved_count }} / {{ $product->sold_count }}</td>
                <td class="admin-ui-number">{{ $pending->get($product->id, 0) }}</td>
                <td class="admin-ui-row-actions"><div><a class="admin-ui-link" href="/admin/inventory/cards?product_id={{ $product->id }}">查看库存</a><a class="admin-ui-link" href="/admin/fulfillment?product_id={{ $product->id }}">查看交付</a></div></td>
            </tr>@empty<tr><td colspan="5" class="admin-ui-empty"><strong>没有匹配的本地卡池商品</strong><p>可调整搜索条件后重试。</p></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$products" />
    </x-admin.card>
</div>
</x-filament-panels::page>
