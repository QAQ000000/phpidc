<x-filament-panels::page>
@include('filament.inventory-feedback')
@can('inventory.import')
<section class="inventory-box"><h2>导入卡密</h2><p class="inventory-note">一行一张，按商品去重并加密保存。导入不会自动恢复已失败任务；请前往「已付待交付」确认重试。</p>
<form method="post" action="{{ route('inventory.import') }}" class="inventory-form">@csrf
<label>本地卡池商品<select name="product_id" required><option value="">选择商品</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }} (#{{ $product->id }})</option>@endforeach</select></label>
<label style="width:100%">卡密（提交后清空）<textarea name="codes" required maxlength="500000" autocomplete="off" spellcheck="false" placeholder="每行一张卡密"></textarea></label>
<label style="flex:1">导入原因<input name="reason" required maxlength="1000" placeholder="例如：补充批次 2026-11"></label><button class="inventory-button">加密导入</button>
</form></section>
@endcan
<section class="inventory-box"><h2>卡密元数据</h2><p class="inventory-note">此页仅展示批次和状态，不提供明文查看或导出。</p>
<form method="get" class="inventory-form"><label>商品<select name="product_id"><option value="">所有商品</option>@foreach ($products as $product)<option value="{{ $product->id }}" @selected((string) request('product_id') === (string) $product->id)>{{ $product->name }}</option>@endforeach</select></label><label>状态<select name="status"><option value="">所有状态</option>@foreach (['available'=>'可用','reserved'=>'预留','sold'=>'已售'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></label><label>批次<input name="batch" value="{{ request('batch') }}" maxlength="50"></label><button class="inventory-button">筛选</button></form></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>ID / 商品</th><th>批次 / 状态</th><th class="inventory-secondary">关联实例</th><th class="inventory-secondary">预留到期 / 售出时间</th></tr></thead><tbody>
@forelse ($cards as $card)<tr><td>#{{ $card->id }}<br>{{ $card->product->name }}</td><td><span class="inventory-meta">{{ $card->batch_no }}</span><br>{{ ['available'=>'可用','reserved'=>'预留','sold'=>'已售'][$card->status] ?? $card->status }}</td><td class="inventory-secondary">{{ $card->instance_id ?? '—' }}</td><td class="inventory-secondary">{{ $card->reserved_until ?? '—' }}<br>{{ $card->sold_at ?? '—' }}</td></tr>
@empty<tr><td colspan="4">没有匹配的卡密库存。</td></tr>@endforelse</tbody></table>{{ $cards->links() }}</div>
</x-filament-panels::page>
