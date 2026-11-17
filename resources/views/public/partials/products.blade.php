<div class="grid">
@forelse($products as $product)
<article class="card"><span class="eyebrow">{{ $product->category->name }}</span><h3><a href="{{ $product->publicPath() }}">{{ $product->name }}</a></h3>
<p>{{ \Illuminate\Support\Str::limit(strip_tags($product->description ?? ''), 110) }}</p><div class="more"><a href="{{ $product->publicPath() }}">了解产品与价格 →</a></div></article>
@empty<div class="empty">暂无上架产品，请稍后再来。</div>@endforelse
</div>
