<x-filament-panels::page>
@include('filament.inventory-feedback')
<p>新闻/帮助由栏目决定。预约时间按 {{ config('app.timezone') }} 输入；到时自动公开，草稿与预约内容不进入站点地图。<a class="inventory-link" href="/admin/content">新建 / 清空选择</a></p>
@can('cms.edit')
@if(!$editArticle?->is_published || auth()->user()->can('cms.publish'))
<section class="inventory-box"><h2>{{ $editArticle ? '编辑文章 #'.$editArticle->id : '新建草稿' }}</h2>
<form method="post" action="/admin/content/articles" class="inventory-form">@csrf
@if($editArticle)<input type="hidden" name="id" value="{{ $editArticle->id }}">@endif
<label>栏目<select name="category_id" required @disabled($editArticle)>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) ($editArticle?->cms_category_id ?? old('category_id')) === (string) $category->id)>{{ $category->type->value }} / {{ $category->name }}{{ $category->is_hidden ? '（隐藏）' : '' }}</option>@endforeach</select></label>
@if($editArticle)<input type="hidden" name="category_id" value="{{ $editArticle->cms_category_id }}"><p>文章保留原栏目；跨栏目迁移未开放。</p>@endif
<label>标题<input name="title" value="{{ old('title', $editArticle?->title) }}" required maxlength="255"></label>
<label>URL slug<input name="slug" value="{{ old('slug', $editArticle?->slug) }}" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100"></label>
<label>格式<select name="content_format"><option value="markdown" @selected(old('content_format', $editArticle?->content_format?->value)==='markdown')>Markdown</option><option value="html" @selected(old('content_format', $editArticle?->content_format?->value)==='html')>HTML（清洗后保存）</option></select></label>
<label style="width:100%">正文<textarea name="body" required maxlength="1000000">{{ old('body', $editArticle?->body) }}</textarea></label>
<label>SEO 标题<input name="seo_title" value="{{ old('seo_title', $editArticle?->seo_title) }}" maxlength="255"></label>
<label>SEO 关键词<input name="seo_keywords" value="{{ old('seo_keywords', $editArticle?->seo_keywords) }}" maxlength="255"></label>
<label>SEO 描述<input name="seo_description" value="{{ old('seo_description', $editArticle?->seo_description) }}" maxlength="1000"></label>
<label>排序<input type="number" name="sort_order" min="0" value="{{ old('sort_order', $editArticle?->sort_order ?? 0) }}"></label>
@can('cms.publish')
<label>发布状态<select name="is_published"><option value="0" @selected(!old('is_published', $editArticle?->is_published))>草稿 / 撤回</option><option value="1" @selected(old('is_published', $editArticle?->is_published))>发布（未来时间为预约）</option></select></label>
<label>公开时间<input name="published_at" type="datetime-local" value="{{ old('published_at', $editArticle?->published_at?->format('Y-m-d\TH:i')) }}"><span class="inventory-meta">留空立即发布</span></label>
@else<input type="hidden" name="is_published" value="0">@endcan
<label>修改原因<input name="reason" required maxlength="1000"></label><button class="inventory-button">保存文章</button>
</form></section>
@else<p class="inventory-alert">此文章已发布或预约，需发布权限才能编辑或撤回。</p>@endif
@endcan
<section class="inventory-box inventory-scroll"><h2>文章</h2><table class="inventory-table"><thead><tr><th>栏目 / 标题</th><th>状态 / 时间</th><th>操作</th></tr></thead><tbody>
@foreach($articles as $article)<tr><td>{{ $article->category->name }} / {{ $article->title }}<br>{{ $article->slug }}</td><td>{{ !$article->is_published ? '草稿' : ($article->published_at?->isFuture() ? '预约发布' : '已发布') }}<br>{{ $article->published_at }}</td><td><a href="?article={{ $article->id }}" class="inventory-link">编辑</a> · <a class="inventory-link" href="/admin/content/articles/{{ $article->id }}/preview">内部预览</a></td></tr>@endforeach
</tbody></table>{{ $articles->links() }}</section>
@can('cms.publish')
<section class="inventory-box"><h2>{{ $editCategory ? '编辑栏目 #'.$editCategory->id : '新建栏目' }}</h2><form method="post" action="/admin/content/categories" class="inventory-form">@csrf
@if($editCategory)<input type="hidden" name="id" value="{{ $editCategory->id }}">@endif
<label>类型<select name="type" @disabled($editCategory)><option value="news" @selected($editCategory?->type->value==='news')>新闻</option><option value="help" @selected($editCategory?->type->value==='help')>帮助</option></select></label>
@if($editCategory)<input type="hidden" name="type" value="{{ $editCategory->type->value }}"><p>既有栏目类型固定，保持公开链接前缀。</p>@endif
<label>名称<input name="name" value="{{ $editCategory?->name }}" required maxlength="255"></label><label>URL slug<input name="slug" value="{{ $editCategory?->slug }}" required maxlength="100"></label>
<label>可见性<select name="is_hidden"><option value="0">公开</option><option value="1" @selected($editCategory?->is_hidden)>隐藏</option></select></label>
<label>SEO 标题<input name="seo_title" value="{{ $editCategory?->seo_title }}" maxlength="255"></label><label>SEO 关键词<input name="seo_keywords" value="{{ $editCategory?->seo_keywords }}" maxlength="255"></label><label>SEO 描述<input name="seo_description" value="{{ $editCategory?->seo_description }}" maxlength="1000"></label><label>排序<input name="sort_order" type="number" min="0" value="{{ $editCategory?->sort_order ?? 0 }}"></label>
<label>修改原因<input name="reason" required maxlength="1000"></label><button class="inventory-button">保存栏目</button></form></section>
@endcan
<section class="inventory-box"><h2>栏目</h2><ul>@foreach($categories as $category)<li><a href="?category={{ $category->id }}" class="inventory-link">{{ $category->type->value }} / {{ $category->name }}</a> · {{ $category->is_hidden ? '隐藏' : '公开' }}</li>@endforeach</ul></section>
@can('cms.publish')
@if($editProduct)<section class="inventory-box"><h2>编辑商品展示 #{{ $editProduct->id }}</h2><p class="inventory-note">仅维护名称、描述与商品可见性；价格、驱动和上游绑定在各产品管理流程维护。SEO 使用名称与描述。</p>
<form method="post" action="/admin/content/products/{{ $editProduct->id }}" class="inventory-form">@csrf<label>名称<input name="name" value="{{ $editProduct->name }}" required maxlength="255"></label><label style="width:100%">描述<textarea name="description" required maxlength="100000">{{ $editProduct->description }}</textarea></label><label>可见性<select name="is_hidden"><option value="0">上架</option><option value="1" @selected($editProduct->is_hidden)>隐藏</option></select></label><label>修改原因<input name="reason" required maxlength="1000"></label><button class="inventory-button">保存商品展示</button></form></section>@endif
@endcan
<section class="inventory-box inventory-scroll"><h2>商品展示</h2><table class="inventory-table"><thead><tr><th>分类 / 商品</th><th>可见性</th><th>操作</th></tr></thead><tbody>@foreach($products as $product)<tr><td>{{ $product->category->name }} / {{ $product->name }}</td><td>{{ $product->is_hidden || $product->category->is_hidden ? '隐藏' : '上架' }}</td><td><a href="?product={{ $product->id }}" class="inventory-link">编辑展示</a></td></tr>@endforeach</tbody></table>{{ $products->links() }}</section>
</x-filament-panels::page>
