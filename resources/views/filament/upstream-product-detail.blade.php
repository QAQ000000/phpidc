<x-admin.card :title="$selectedProduct->name" description="本地配置详情；同步与边界保存仍使用原业务流程。">
    <x-slot:actions>
        <a class="admin-ui-button" href="{{ $detailReturnUrl }}">返回商品列表</a>
        @if(in_array($selectedProduct->id, $previewableProductIds, true))<a class="admin-ui-button" href="/console/products/{{ $selectedProduct->id }}/configure" target="_blank" rel="noopener">查看配置界面 ↗</a>@endif
    </x-slot:actions>
    <div class="admin-ui-card-body">
        <dl class="admin-ui-facts">
            <div><dt>本地商品</dt><dd>#{{ $selectedProduct->id }} <x-admin.badge :tone="$selectedProduct->is_hidden ? 'neutral' : 'success'">{{ $selectedProduct->is_hidden ? '隐藏' : '显示' }}</x-admin.badge></dd></div>
            <div><dt>上游连接 / 商品</dt><dd>#{{ $selectedProduct->upstream_api_id }} / #{{ $selectedProduct->upstream_pid }}</dd></div>
            <div><dt>目录版本</dt><dd>{{ $selectedProduct->upstream_version ?? '未同步' }}</dd></div>
            <div><dt>基础计费</dt><dd>@include('filament.admin-product-price', ['product' => $selectedProduct])</dd></div>
            <div><dt>加价比例</dt><dd>{{ $selectedProduct->markup_percent }}%</dd></div>
            <div><dt>已同步库存</dt><dd>{{ !$selectedProduct->stock_control ? '不限量' : ($selectedProduct->qty === null ? '未知' : $selectedProduct->qty) }}</dd></div>
        </dl>
        @can('upstream.import')<form method="post" action="/admin/upstreams/{{ $selectedProduct->id }}/sync" class="inventory-form">@csrf
            <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details>
            <button class="inventory-button">同步配置与价格</button>
        </form>@endcan
    </div>
</x-admin.card>
<x-admin.card title="配置项与未来续费边界" description="选项来自本地完整配置快照，隐藏状态和降价边界分别保留。">
    @forelse($selectedProduct->optionGroups as $group)
        <section class="admin-ui-option">
            <div class="admin-ui-option-heading"><h3>{{ $group->name }} <span class="admin-ui-meta">#{{ $group->id }} · {{ $group->type }}</span></h3><x-admin.badge :tone="$group->is_hidden ? 'neutral' : 'success'">{{ $group->is_hidden ? '隐藏' : '显示' }}</x-admin.badge></div>
            <ul class="admin-ui-option-list">@forelse($group->items as $item)<li>#{{ $item->id }} {{ $item->label }}{{ $item->is_hidden ? '（已下架）' : '' }}</li>@empty<li>没有配置子项</li>@endforelse</ul>
            @can('upstream.import')<details><summary>未来续费降价边界（远程配置不变）</summary>
                <form class="inventory-form" method="post" action="/admin/upstream-options/{{ $group->id }}/bounds">@csrf
                    <label><input type="checkbox" name="allow" value="1" @checked($group->downgrade_bounds['allow_downgrade'] ?? false)>允许降低未来续费价</label>
                    @if(in_array($group->type, ['quantity','slider'], true))<label>最低数量<input type="number" name="minimum" min="{{ $group->min_value }}" max="{{ $group->max_value }}" step="{{ $group->step }}" value="{{ $group->downgrade_bounds['min_value'] ?? $group->min_value }}"></label>
                    @else @foreach($group->items->where('is_hidden', false) as $item)<label><input type="checkbox" name="items[]" value="{{ $item->id }}" @checked(in_array($item->id, $group->downgrade_bounds['allowed_item_ids'] ?? [], true))>允许 {{ $item->label }}</label>@endforeach @endif
                    <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details><button class="inventory-button">保存降价边界</button>
                </form>
            </details>@endcan
        </section>
    @empty<div class="admin-ui-empty">此商品没有配置项。</div>@endforelse
</x-admin.card>
