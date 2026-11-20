<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b1">
@php
    $articleError = $formError === 'cms-article';
    $categoryError = $formError === 'cms-category';
    $productError = $formError === 'cms-product';
    $articleValue = fn ($key, $default = null) => $articleError ? old($key, $default) : $default;
    $categoryValue = fn ($key, $default = null) => $categoryError ? old($key, $default) : $default;
    $productValue = fn ($key, $default = null) => $productError ? old($key, $default) : $default;
    $canEditArticle = auth()->user()->can('cms.edit') && (!$editArticle?->is_published || auth()->user()->can('cms.publish'));
    $canPublish = auth()->user()->can('cms.edit') && auth()->user()->can('cms.publish');
    $openDialog = match (true) {
        $formError === 'cms-article' && $canEditArticle => 'cms-article',
        $formError === 'cms-category' && $canPublish => 'cms-category',
        $formError === 'cms-product' && $canPublish && $editProduct !== null => 'cms-product',
        $formError !== null => null,
        $editArticle !== null && $canEditArticle => 'cms-article',
        $editCategory !== null && $canPublish => 'cms-category',
        $editProduct !== null && $canPublish => 'cms-product',
        default => null,
    };
    $contentUrl = fn ($key, $id) => '/admin/content?'.http_build_query([...request()->except(['article', 'category', 'product']), $key => $id]);
@endphp
<div class="admin-ui admin-operations-page" x-data @if($openDialog) x-init="$nextTick(() => document.getElementById('{{ $openDialog }}')?.showModal())" @endif>
    @include('filament.inventory-feedback')
    <header class="admin-ui-heading">
        <div><p>新闻/帮助由栏目决定。预约时间按 {{ config('app.timezone') }} 输入；到时自动公开，草稿与预约内容不进入站点地图。</p></div>
        <div class="admin-ui-actions">
            @can('cms.edit')
                @if($editArticle)<a class="admin-ui-button admin-ui-button-primary" href="/admin/content">新建草稿 / 清空选择</a>
                @else<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('cms-article').showModal()">新建草稿</button>@endif
            @endcan
            @if($canPublish)
                @if($editCategory)<a class="admin-ui-button" href="/admin/content">新建栏目 / 清空选择</a>
                @else<button type="button" class="admin-ui-button" x-on:click="document.getElementById('cms-category').showModal()">新建栏目</button>@endif
            @endif
        </div>
    </header>
    @if($editArticle && !$canEditArticle && auth()->user()->can('cms.edit'))<p class="inventory-alert">此文章已发布或预约，需发布权限才能编辑或撤回。</p>@endif

    <x-admin.card title="文章" description="按创建顺序展示文章；内部预览不等于公开发布。">
        <x-admin.table label="文章列表">
            <x-slot:head><tr><th scope="col">栏目 / 标题</th><th scope="col">发布状态</th><th scope="col">公开时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($articles as $article)
                <tr>
                    <td class="admin-ui-product-name">{{ $article->title }}<span class="admin-ui-meta">{{ $article->category->name }} · #{{ $article->id }} · {{ $article->slug }}</span></td>
                    <td><x-admin.badge :tone="!$article->is_published ? 'neutral' : ($article->published_at?->isFuture() ? 'warning' : 'success')">{{ !$article->is_published ? '草稿' : ($article->published_at?->isFuture() ? '预约发布' : '已发布') }}</x-admin.badge></td>
                    <td class="admin-ui-nowrap">{{ $article->published_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="admin-ui-row-actions"><div>
                        @if(auth()->user()->can('cms.edit') && (!$article->is_published || $canPublish))<a class="admin-ui-link" href="{{ $contentUrl('article', $article->id) }}">编辑</a>@endif
                        <a class="admin-ui-link" href="/admin/content/articles/{{ $article->id }}/preview">内部预览</a>
                    </div></td>
                </tr>
            @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无文章</strong><p>创建草稿后，可在此管理发布与预览。</p></td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$articles" />
    </x-admin.card>

    <x-admin.card title="栏目" description="新闻与帮助的分类和公开链接前缀。隐藏栏目不会出现在公开列表。">
        <x-admin.table label="栏目列表">
            <x-slot:head><tr><th scope="col">栏目名称</th><th scope="col">类型 / URL slug</th><th scope="col">可见性</th><th scope="col" class="admin-ui-number">排序</th>@if($canPublish)<th scope="col" class="admin-ui-row-actions">操作</th>@endif</tr></x-slot:head>
            @forelse($categories as $category)
                <tr><td class="admin-ui-product-name">{{ $category->name }}<span class="admin-ui-meta">#{{ $category->id }}</span></td><td>{{ $category->type->value === 'news' ? '新闻' : '帮助' }}<span class="admin-ui-meta">{{ $category->slug }}</span></td><td><x-admin.badge :tone="$category->is_hidden ? 'neutral' : 'success'">{{ $category->is_hidden ? '隐藏' : '公开' }}</x-admin.badge></td><td class="admin-ui-number">{{ $category->sort_order }}</td>@if($canPublish)<td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ $contentUrl('category', $category->id) }}">编辑栏目</a></td>@endif</tr>
            @empty<tr><td colspan="{{ $canPublish ? 5 : 4 }}" class="admin-ui-empty"><strong>暂无栏目</strong><p>发布管理员可先创建新闻或帮助栏目。</p></td></tr>@endforelse
        </x-admin.table>
    </x-admin.card>

    <x-admin.card title="商品展示" description="管理商城名称、描述与展示状态；价格、驱动和上游绑定仍由原商品管理流程维护。">
        <x-admin.table label="商品展示列表">
            <x-slot:head><tr><th scope="col">分类 / 商品</th><th scope="col">展示状态</th>@if($canPublish)<th scope="col" class="admin-ui-row-actions">操作</th>@endif</tr></x-slot:head>
            @forelse($products as $product)
                <tr><td class="admin-ui-product-name">{{ $product->name }}<span class="admin-ui-meta">{{ $product->category->name }} · #{{ $product->id }}</span></td><td><x-admin.badge :tone="$product->is_hidden || $product->category->is_hidden ? 'neutral' : 'success'">{{ $product->is_hidden || $product->category->is_hidden ? '隐藏' : '上架' }}</x-admin.badge>@if($product->category->is_hidden)<span class="admin-ui-meta">所属分类已隐藏</span>@endif</td>@if($canPublish)<td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ $contentUrl('product', $product->id) }}">编辑展示</a></td>@endif</tr>
            @empty<tr><td colspan="{{ $canPublish ? 3 : 2 }}" class="admin-ui-empty"><strong>暂无商品</strong><p>商品导入或创建后，可在此维护商城展示。</p></td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$products" />
    </x-admin.card>

    @if($canEditArticle)@include('filament.content.article-form')@endif
    @if($canPublish)
        @include('filament.content.category-form')
        @if($editProduct)@include('filament.content.product-form')@endif
    @endif
</div>
</x-filament-panels::page>
