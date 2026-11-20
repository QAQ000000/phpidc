@php
    $restoreRule = $restoreContext === 'affiliate-rule' && (string) old('id', '') === (string) ($editing?->id ?? '');
    $ruleValue = fn ($key, $default = null) => $restoreRule ? old($key, $default) : $default;
    $openRule = $errors->any() ? $restoreRule : ($editing !== null || request()->boolean('create'));
@endphp
<header class="admin-ui-heading"><div><p>只对 CNY 新购计佣。priority 降序、ID 升序；未绑定商品表示全局规则。新规则不改变已建单快照。</p></div>
    @can('affiliate.manage')<div class="admin-ui-actions">@if($editing)<a class="admin-ui-button admin-ui-button-primary" href="/admin/affiliates?create=1">创建推广规则</a>@else<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('affiliate-rule-editor').showModal()">创建推广规则</button>@endif</div>@endcan
</header>
<x-admin.card title="推广规则">
    <x-admin.table label="推广规则"><x-slot:head><tr><th scope="col">规则</th><th scope="col" class="admin-ui-number">费率 / 优先级</th><th scope="col">状态</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr><td class="admin-ui-product-name">{{ $record->name }}<span class="admin-ui-meta">规则 #{{ $record->id }}</span></td><td class="admin-ui-number">{{ $record->commission_rate }}%<span class="admin-ui-meta">priority {{ $record->priority }}</span></td><td><x-admin.badge :tone="$record->is_active ? 'success' : 'neutral'">{{ $record->is_active ? '启用' : '停用' }}</x-admin.badge></td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['edit' => $record->id, 'create' => null]) }}">{{ auth()->user()->can('affiliate.manage') ? '查看 / 编辑' : '查看' }}</a></td></tr>
        @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无推广规则</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
<x-admin.card title="佣金明细" description="按推广人筛选已记录的佣金，保留原账单费率快照。">
    <x-admin.filters action="/admin/affiliates"><label>推广人 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label></x-admin.filters>
    <x-admin.table label="佣金明细"><x-slot:head><tr><th scope="col">佣金 / 账单</th><th scope="col">推广人 / 买家</th><th scope="col" class="admin-ui-number">金额 / 费率</th><th scope="col">状态 / 成熟时间</th></tr></x-slot:head>
        @forelse($commissions as $commission)<tr><td>#{{ $commission->id }}<span class="admin-ui-meta">账单 #{{ $commission->invoice_id }}</span></td><td>推广人 #{{ $commission->referrer_id }}<span class="admin-ui-meta">买家 #{{ $commission->buyer_id }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $commission->amount }} CNY<span class="admin-ui-meta">{{ $commission->rate_snapshot }}%</span></td><td><x-admin.badge :tone="$commission->status === 'available' ? 'success' : ($commission->status === 'pending' ? 'warning' : 'neutral')">{{ $commission->status }}</x-admin.badge><span class="admin-ui-meta">{{ $commission->available_at }}</span></td></tr>
        @empty<tr><td colspan="4" class="admin-ui-empty"><strong>暂无匹配佣金</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$commissions" />
</x-admin.card>
@can('affiliate.manage')
    <x-admin.dialog id="affiliate-rule-editor" :title="$editing ? '编辑推广规则 #'.$editing->id : '创建推广规则'" :show-errors="$restoreRule" x-init="{{ $openRule ? '$nextTick(() => $el.showModal())' : '' }}">
        <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/config/rule">@csrf<input type="hidden" name="_admin_form" value="affiliate-rule"><input type="hidden" name="id" value="{{ $editing?->id }}">
            <label>规则名称<input name="name" required value="{{ $ruleValue('name', $editing?->name) }}"></label><label>费率 %<input name="commission_rate" required value="{{ $ruleValue('commission_rate', $editing?->commission_rate ?? '10.00') }}"></label><label>优先级<input name="priority" type="number" min="0" required value="{{ $ruleValue('priority', $editing?->priority ?? 0) }}"></label>
            <label>状态<select name="is_active"><option value="1" @selected((string) $ruleValue('is_active', $editing?->is_active ?? true) === '1')>启用</option><option value="0" @selected((string) $ruleValue('is_active', $editing?->is_active ?? true) !== '1')>停用</option></select></label>
            @include('filament.operations-product-selector', ['selectedProductIds' => $productIds, 'allWhenEmpty' => true, 'restoreOldInputs' => $restoreRule])
            <label class="admin-operations-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $ruleValue('reason') }}"></label>
            <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">保存规则</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@else
    @if($editing)<x-admin.card :title="'推广规则 #'.$editing->id" description="当前为只读查看。"><div class="admin-ui-card-body"><dl class="admin-ui-facts"><div><dt>名称</dt><dd>{{ $editing->name }}</dd></div><div><dt>费率</dt><dd>{{ $editing->commission_rate }}%</dd></div><div><dt>优先级</dt><dd>{{ $editing->priority }}</dd></div><div><dt>适用商品</dt><dd>{{ $productIds === [] ? '所有商品' : implode('、', $productIds) }}</dd></div></dl></div></x-admin.card>@endif
@endcan
