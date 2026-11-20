@php
    $restoreDownload = $restoreContext === 'download' && (string) old('id', '') === (string) ($editing?->id ?? '');
    $downloadValue = fn ($key, $default = null) => $restoreDownload ? old($key, $default) : $default;
    $openDownload = $errors->any() ? $restoreDownload : ($editing !== null || request()->boolean('create'));
@endphp
<header class="admin-ui-heading"><div><p>本地文件保存在私有磁盘。下载量是接受请求数，不能证明传输完成；受限外链只在授权后跳转，外部链接可能被分享。</p></div>
    @can('download.manage')<div class="admin-ui-actions">@if($editing)<a class="admin-ui-button admin-ui-button-primary" href="/admin/downloads?create=1">创建下载资源</a>@else<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('download-editor').showModal()">创建下载资源</button>@endif</div>@endcan
</header>
<x-admin.card title="下载资源">
    <x-admin.table label="下载资源"><x-slot:head><tr><th scope="col">资源 / 版本</th><th scope="col">访问要求 / 状态</th><th scope="col">文件信息</th><th scope="col" class="admin-ui-number">请求数</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr><td class="admin-ui-product-name">{{ $record->title }}<span class="admin-ui-meta">#{{ $record->id }} · {{ $record->category }} · {{ $record->version ?? '未设置版本' }}</span></td><td>{{ match($record->access_level) { \App\Enums\DownloadAccessLevel::PUBLIC => '公开下载', \App\Enums\DownloadAccessLevel::AUTHENTICATED => '登录后下载', \App\Enums\DownloadAccessLevel::ACTIVE_CUSTOMER => '有效服务客户' } }}<span class="admin-ui-meta"><x-admin.badge :tone="$record->is_active ? 'success' : 'neutral'">{{ $record->is_active ? '启用' : '停用' }}</x-admin.badge></span></td><td><span>{{ $record->storage_type === 'local' ? '私有文件' : '外部链接' }} · {{ $record->file_size ?? '—' }} bytes</span><details><summary>SHA256</summary><span class="admin-ui-meta">{{ $record->sha256 ?? '—' }}</span></details></td><td class="admin-ui-number">{{ $record->download_count }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['edit' => $record->id, 'create' => null]) }}">{{ auth()->user()->can('download.manage') ? '查看 / 编辑' : '查看' }}</a></td></tr>
        @empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无下载资源</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
@can('download.manage')
    <x-admin.dialog id="download-editor" :title="$editing ? '编辑下载资源 #'.$editing->id : '创建下载资源'" :show-errors="$restoreDownload" x-init="{{ $openDownload ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="inventory-form admin-operations-form" method="post" enctype="multipart/form-data" action="/admin/operations/downloads" x-data="{ storage: @js($downloadValue('storage_type', $editing?->storage_type ?? 'local')) }">@csrf
            <input type="hidden" name="_admin_form" value="download"><input type="hidden" name="id" value="{{ $editing?->id }}">
            <label>标题<input name="title" required maxlength="255" value="{{ $downloadValue('title', $editing?->title) }}"></label><label>分类<input name="category" required maxlength="64" value="{{ $downloadValue('category', $editing?->category ?? 'general') }}"></label>
            <label>版本<input name="version" maxlength="64" value="{{ $downloadValue('version', $editing?->version) }}"></label><label>存储方式<select name="storage_type" x-model="storage"><option value="local" @selected($downloadValue('storage_type', $editing?->storage_type ?? 'local') === 'local')>上传文件</option><option value="external_url" @selected($downloadValue('storage_type', $editing?->storage_type) === 'external_url')>外部链接</option></select></label>
            <label class="admin-operations-wide">说明<textarea name="description">{{ $downloadValue('description', $editing?->description) }}</textarea></label>
            <fieldset class="admin-operations-fieldset admin-operations-wide" x-show="storage === 'local'" x-bind:disabled="storage !== 'local'"><label>私有文件（最大 50 MB；编辑留空保留）<input type="file" name="file"></label>@if($restoreDownload)<p class="admin-operations-note">浏览器不会恢复已选文件；需要上传时请重新选择文件。编辑留空仍保留原文件。</p>@endif</fieldset>
            <fieldset class="admin-operations-fieldset admin-operations-wide" x-show="storage === 'external_url'" x-bind:disabled="storage !== 'external_url'"><label>HTTPS 外链<input name="external_url" type="url" value="{{ $downloadValue('external_url', $editing?->external_url) }}"></label></fieldset>
            <label>访问要求<select name="access_level">@foreach(\App\Enums\DownloadAccessLevel::cases() as $level)<option value="{{ $level->value }}" @selected($downloadValue('access_level', $editing?->access_level?->value) === $level->value)>{{ match($level) { \App\Enums\DownloadAccessLevel::PUBLIC => '公开下载', \App\Enums\DownloadAccessLevel::AUTHENTICATED => '登录后下载', \App\Enums\DownloadAccessLevel::ACTIVE_CUSTOMER => '有效服务客户' } }}</option>@endforeach</select></label>
            <label>排序<input name="sort_order" type="number" min="0" value="{{ $downloadValue('sort_order', $editing?->sort_order ?? 0) }}" required></label><label>状态<select name="is_active"><option value="1" @selected((string) $downloadValue('is_active', $editing?->is_active ?? true) === '1')>启用</option><option value="0" @selected((string) $downloadValue('is_active', $editing?->is_active ?? true) !== '1')>停用</option></select></label>
            <label>备注（可选）<input name="reason" maxlength="255" value="{{ $downloadValue('reason') }}"></label>
            <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">保存资源</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@else
    @if($editing)<x-admin.card :title="'下载资源 #'.$editing->id" description="当前为只读查看。"><div class="admin-ui-card-body"><p>{{ $editing->title }} {{ $editing->version }}</p><div style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $editing->description }}</div></div></x-admin.card>@endif
@endcan
