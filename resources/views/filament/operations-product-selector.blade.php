@php
    $selectedProductIds = old('product_ids', $selectedProductIds ?? null);
    $selectedProductIds = is_array($selectedProductIds) || $selectedProductIds === null ? $selectedProductIds : [];
    $selectedProductIds = $selectedProductIds === null ? null : array_map('strval', array_filter($selectedProductIds, 'is_scalar'));
    $productScope = old('product_scope', $selectedProductIds === null || ($allWhenEmpty ?? false) && $selectedProductIds === [] ? 'all' : 'selected');
@endphp
<fieldset class="inventory-box" style="width:100%" x-data="{ search: '', scope: @js($productScope) }">
    <legend>适用商品</legend>
    <label>商品范围<select name="product_scope" x-model="scope"><option value="all" @selected($productScope === 'all')>所有商品</option><option value="selected" @selected($productScope === 'selected')>指定商品</option></select></label>
    <div x-show="scope === 'selected'">
        @if(!($allWhenEmpty ?? false))<p>仅勾选的商品可使用此券；不选商品表示暂无适用商品。</p>@endif
        <label>搜索商品<input type="search" x-model="search" placeholder="输入商品名称" autocomplete="off"></label>
        <div style="max-height:14rem;overflow:auto">
            @forelse($availableProducts as $selectableProduct)
                <label data-label="{{ mb_strtolower($selectableProduct->name) }}" x-show="$el.dataset.label.includes(search.toLocaleLowerCase())" style="display:block">
                    <input type="checkbox" name="product_ids[]" value="{{ $selectableProduct->id }}" @checked(in_array((string) $selectableProduct->id, $selectedProductIds ?? [], true)) x-bind:disabled="scope !== 'selected'">
                    {{ $selectableProduct->name }}
                </label>
            @empty<p>暂无商品，请先创建商品。</p>@endforelse
        </div>
    </div>
</fieldset>
