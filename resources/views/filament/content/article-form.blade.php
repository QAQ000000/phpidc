<x-admin.dialog id="cms-article" :title="$editArticle ? '编辑文章 #'.$editArticle->id : '新建草稿'" :show-errors="$articleError">
<form method="post" action="/admin/content/articles" class="admin-operations-form">@csrf
<input type="hidden" name="_admin_form" value="cms-article">
@if($editArticle)<input type="hidden" name="id" value="{{ $editArticle->id }}">@endif
<label>栏目<select name="category_id" required @disabled($editArticle)>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) ($editArticle?->cms_category_id ?? $articleValue('category_id')) === (string) $category->id)>{{ $category->type->value }} / {{ $category->name }}{{ $category->is_hidden ? '（隐藏）' : '' }}</option>@endforeach</select></label>
@if($editArticle)<input type="hidden" name="category_id" value="{{ $editArticle->cms_category_id }}"><p class="admin-operations-note admin-operations-wide">文章保留原栏目；跨栏目迁移未开放。</p>@endif
<label>标题<input name="title" value="{{ $articleValue('title', $editArticle?->title) }}" required maxlength="255"></label>
<label>URL slug<input name="slug" value="{{ $articleValue('slug', $editArticle?->slug) }}" required pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="100"></label>
<label>格式<select name="content_format"><option value="markdown" @selected($articleValue('content_format', $editArticle?->content_format?->value)==='markdown')>Markdown</option><option value="html" @selected($articleValue('content_format', $editArticle?->content_format?->value)==='html')>HTML（清洗后保存）</option></select></label>
<label class="admin-operations-wide">正文<textarea name="body" required maxlength="1000000" rows="10">{{ $articleValue('body', $editArticle?->body) }}</textarea></label>
<label>SEO 标题<input name="seo_title" value="{{ $articleValue('seo_title', $editArticle?->seo_title) }}" maxlength="255"></label>
<label>SEO 关键词<input name="seo_keywords" value="{{ $articleValue('seo_keywords', $editArticle?->seo_keywords) }}" maxlength="255"></label>
<label>SEO 描述<input name="seo_description" value="{{ $articleValue('seo_description', $editArticle?->seo_description) }}" maxlength="1000"></label>
<label>排序<input type="number" name="sort_order" min="0" value="{{ $articleValue('sort_order', $editArticle?->sort_order ?? 0) }}"></label>
@can('cms.publish')
<label>发布状态<select name="is_published"><option value="0" @selected(!$articleValue('is_published', $editArticle?->is_published))>草稿 / 撤回</option><option value="1" @selected($articleValue('is_published', $editArticle?->is_published))>发布（未来时间为预约）</option></select></label>
<label>公开时间<input name="published_at" type="datetime-local" value="{{ $articleValue('published_at', $editArticle?->published_at?->format('Y-m-d\TH:i')) }}"><span class="admin-ui-meta">留空立即发布</span></label>
@else<input type="hidden" name="is_published" value="0">@endcan
<label>备注（可选）<input name="reason" maxlength="255" value="{{ $articleValue('reason') }}"></label><div class="admin-operations-form-actions"><button type="submit" class="admin-ui-button admin-ui-button-primary">保存文章</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
</form>
</x-admin.dialog>
