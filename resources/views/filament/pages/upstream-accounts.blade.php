<x-filament-panels::page>
@include('filament.admin-ui-styles')
@php($reopenDialog = $errors->any() && in_array(old('ui_dialog'), ['upstream-connections', 'upstream-import'], true) ? old('ui_dialog') : null)
@php($hasFilters = array_filter($filters, fn (string $value): bool => $value !== '') !== [])
<div class="admin-ui" x-data @if($reopenDialog) x-init="$nextTick(() => document.getElementById('{{ $reopenDialog }}')?.showModal())" @endif>
    @include('filament.inventory-feedback')
    <header class="admin-ui-heading">
        <div><h2>{{ $selectedProduct ? '商品配置详情' : '经典魔方商品' }}</h2><p>集中管理已导入商品、完整配置和上游连接。基础价不含配置项及初装费。</p></div>
        <div class="admin-ui-actions">
            <button type="button" class="admin-ui-button" x-on:click="document.getElementById('upstream-connections').showModal()">上游连接管理</button>
            @can('upstream.import')<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('upstream-import').showModal()">导入商品</button>@endcan
        </div>
    </header>
    @if($selectedProduct)
        @include('filament.upstream-product-detail')
    @else
        <x-admin.card title="商品列表" description="本地展示状态与实际购买准入分别判断；库存和价格来自最近保存的目录。">
            <x-admin.filters action="/admin/upstreams">
                <label class="admin-ui-search" for="product-search">关键词<input id="product-search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="商品名称 / 本地 ID / 上游 PID"></label>
                <label for="product-account">上游连接<select id="product-account" name="account_id"><option value="">全部连接</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($filters['account_id'] === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
                <label for="product-visibility">本地展示<select id="product-visibility" name="visibility"><option value="">全部状态</option><option value="visible" @selected($filters['visibility'] === 'visible')>显示</option><option value="hidden" @selected($filters['visibility'] === 'hidden')>隐藏</option></select></label>
            </x-admin.filters>
            <x-admin.table label="经典魔方商品列表">
                <x-slot:head><tr><th scope="col">商品</th><th scope="col">上游连接 / PID</th><th scope="col">本地展示</th><th scope="col">同步库存</th><th scope="col" class="admin-ui-number">基础价格</th><th scope="col" class="admin-ui-number">配置组</th><th scope="col">更新信息</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
                @forelse($products as $product)
                    @php($connection = $accounts->firstWhere('id', $product->upstream_api_id))
                    @php($detailUrl = '/admin/upstreams?'.http_build_query([...array_filter([...$filters, 'page' => request()->query('page')], fn ($value) => $value !== null && $value !== ''), 'product' => $product->id]))
                    <tr>
                        <td class="admin-ui-product-name"><a class="admin-ui-link" href="{{ $detailUrl }}">{{ $product->name }}</a><span class="admin-ui-meta">#{{ $product->id }}</span></td>
                        <td><span class="admin-ui-nowrap">{{ $connection?->name ?? '未关联连接' }}</span><span class="admin-ui-meta">#{{ $product->upstream_api_id }} / PID {{ $product->upstream_pid }}@if($connection && !$connection->is_active) · 连接停用@endif</span></td>
                        <td><x-admin.badge :tone="$product->is_hidden ? 'neutral' : 'success'">{{ $product->is_hidden ? '隐藏' : '显示' }}</x-admin.badge></td>
                        <td class="admin-ui-nowrap">@if(!$product->stock_control)<span class="admin-ui-meta">不限量</span>@elseif($product->qty === null)<span class="admin-ui-meta">未知</span>@else<x-admin.badge :tone="$product->qty > 0 ? 'neutral' : 'warning'">{{ $product->qty > 0 ? $product->qty.' 件' : '库存为 0' }}</x-admin.badge>@endif</td>
                        <td class="admin-ui-number">@include('filament.admin-product-price')</td>
                        <td class="admin-ui-number">{{ $product->option_group_count }}</td>
                        <td class="admin-ui-nowrap">{{ $product->updated_at?->format('Y-m-d H:i') ?? '—' }}<span class="admin-ui-meta">目录版本 {{ $product->upstream_version ?? '未同步' }}</span></td>
                        <td class="admin-ui-row-actions"><div><a class="admin-ui-link" href="{{ $detailUrl }}">配置详情</a>
                            @can('upstream.import')<form method="post" action="/admin/upstreams/{{ $product->id }}/sync">@csrf<button type="submit" aria-label="同步{{ $product->name }}的配置与价格">同步</button></form>@endcan
                        </div></td>
                    </tr>
                @empty<tr><td colspan="8" class="admin-ui-empty"><strong>{{ $hasFilters ? '没有匹配商品' : '尚未导入经典魔方商品' }}</strong><p>{{ $hasFilters ? '调整筛选条件或重置后重试。' : '配置上游连接后，可按上游 PID 导入商品与完整配置。' }}</p>
                    @if($hasFilters)<a class="admin-ui-button" href="/admin/upstreams">重置筛选</a>@endif
                </td></tr>@endforelse
            </x-admin.table>
            <x-admin.pagination :paginator="$products" />
        </x-admin.card>
    @endif
    @include('filament.upstream-management-dialogs')
</div>
</x-filament-panels::page>
