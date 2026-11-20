<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b2">
@php
    $restoreImport = $errors->any() && old('_admin_form') === 'inventory-import'
        && (request('product_id') === null || (string) request('product_id') === (string) old('product_id'));
    $importProduct = $restoreImport ? old('product_id') : request('product_id');
    $statusLabels = ['available' => '可用', 'reserved' => '预留', 'sold' => '已售'];
@endphp
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <header class="admin-ui-heading"><div><p>此页仅展示卡密的批次和状态，不提供明文查看或导出。补货后仍需到「已付待交付」核查失败任务。</p></div>
        @can('inventory.import')<div class="admin-ui-actions"><button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('inventory-import').showModal()">导入卡密</button></div>@endcan
    </header>
    <x-admin.card title="卡密元数据">
        <x-admin.filters action="/admin/inventory/cards">
            <label>商品<select name="product_id"><option value="">所有商品</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></label>
            <label>状态<select name="status"><option value="">所有状态</option>@foreach($statusLabels as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label>批次<input name="batch" value="{{ request('batch') }}" maxlength="50"></label>
        </x-admin.filters>
        <x-admin.table label="卡密元数据"><x-slot:head><tr><th scope="col">卡密 / 商品</th><th scope="col">批次 / 状态</th><th scope="col">关联实例</th><th scope="col">预留到期 / 售出时间</th></tr></x-slot:head>
            @forelse($cards as $card)<tr><td class="admin-ui-product-name">{{ $card->product->name }}<span class="admin-ui-meta">卡密 #{{ $card->id }} · 商品 #{{ $card->product_id }}</span></td>
                <td><x-admin.badge :tone="match($card->status) { 'available' => 'success', 'reserved' => 'warning', default => 'neutral' }">{{ $statusLabels[$card->status] ?? $card->status }}</x-admin.badge><span class="admin-ui-meta">{{ $card->batch_no ?? '无批次标识' }}</span></td>
                <td>{{ $card->instance_id ? '#'.$card->instance_id : '未关联' }}</td><td>{{ $card->reserved_until ?? '—' }}<span class="admin-ui-meta">售出 {{ $card->sold_at ?? '—' }}</span></td>
            </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>没有匹配的卡密库存</strong><p>可调整商品、状态或批次筛选。</p></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$cards" />
    </x-admin.card>
    @can('inventory.import')
        <x-admin.dialog id="inventory-import" title="导入卡密" description="一行一张，按商品去重并加密保存。导入不会自动恢复已失败任务。" :show-errors="$restoreImport" x-init="{{ $restoreImport ? '$nextTick(() => $el.showModal())' : '' }}">
            @if($restoreImport)<p class="admin-operations-note">卡密内容未保留，请重新粘贴需要导入的卡密。</p>@endif
            <form method="post" action="{{ route('inventory.import') }}" class="inventory-form admin-operations-form">@csrf
                <input type="hidden" name="_admin_form" value="inventory-import">
                <label class="admin-operations-wide">本地卡池商品<select name="product_id" required><option value="">选择商品</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string) $importProduct === (string) $product->id)>{{ $product->name }} (#{{ $product->id }})</option>@endforeach</select></label>
                <label class="admin-operations-wide">卡密（提交后清空）<textarea name="codes" required maxlength="500000" autocomplete="off" spellcheck="false" placeholder="每行一张卡密"></textarea></label>
                <label class="admin-operations-wide">批次备注（可选）<input name="reason" maxlength="1000" value="{{ $restoreImport ? old('reason') : '' }}" placeholder="可填写采购批次或补充说明"></label>
                <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">加密导入</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endcan
</div>
</x-filament-panels::page>
