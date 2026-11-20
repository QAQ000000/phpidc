@php
    $restoreCoupon = $restoreContext === 'coupon' && (string) old('id', '') === (string) ($editing?->id ?? '');
    $couponValue = fn ($key, $default = null) => $restoreCoupon ? old($key, $default) : $default;
    $openCoupon = $errors->any() ? $restoreCoupon : ($editing !== null || request()->boolean('create'));
    $restoreGrant = $restoreContext === 'coupon-grant';
@endphp
<header class="admin-ui-heading">
    <div><p>百分比优惠填写 10.00 表示减免 10%。支持 CNY 新购和新建续费账单，既有订单金额保持不变。</p></div>
    @can('promotion.manage')<div class="admin-ui-actions">
        @if($editing)<a class="admin-ui-button admin-ui-button-primary" href="/admin/coupons?create=1">创建优惠券</a>@else<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('coupon-editor').showModal()">创建优惠券</button>@endif
        <button type="button" class="admin-ui-button" x-on:click="document.getElementById('coupon-grant').showModal()">定向发放领取资格</button>
    </div>@endcan
</header>
<x-admin.card title="优惠券列表" description="查看已用额度、有效期和启停状态。">
    <x-admin.table label="优惠券列表"><x-slot:head><tr><th scope="col">券码 / 类型</th><th scope="col" class="admin-ui-number">优惠 / 最低额</th><th scope="col" class="admin-ui-number">核销 / 总额</th><th scope="col">状态 / 有效期</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
        @forelse($records as $record)<tr>
            <td class="admin-ui-product-name">{{ $record->code }}<span class="admin-ui-meta">#{{ $record->id }} · {{ $record->type === 'percent' ? '百分比' : '固定金额' }}</span></td>
            <td class="admin-ui-number admin-ui-nowrap">{{ $record->value }}{{ $record->type === 'percent' ? '%' : ' CNY' }}<span class="admin-ui-meta">最低 {{ $record->min_order_amount }} CNY</span></td>
            <td class="admin-ui-number">{{ $record->used_count }} / {{ $record->total_quota ?? '不限' }}</td>
            <td><x-admin.badge :tone="$record->is_active ? 'success' : 'neutral'">{{ $record->is_active ? '启用' : '停用' }}</x-admin.badge><span class="admin-ui-meta">{{ $record->valid_until?->format('Y-m-d H:i') ?? '不限截止时间' }}</span></td>
            <td class="admin-ui-row-actions"><a class="admin-ui-link" href="{{ request()->fullUrlWithQuery(['edit' => $record->id, 'create' => null]) }}">{{ auth()->user()->can('promotion.manage') ? '查看 / 编辑' : '查看' }}</a></td>
        </tr>@empty<tr><td colspan="5" class="admin-ui-empty"><strong>暂无优惠券</strong><p>创建后可在此查看额度和有效期。</p></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$records" />
</x-admin.card>
@can('promotion.manage')
    <x-admin.dialog id="coupon-editor" :title="$editing ? '编辑优惠券 #'.$editing->id : '创建优惠券'" :show-errors="$restoreCoupon" x-init="{{ $openCoupon ? '$nextTick(() => $el.showModal())' : '' }}">
        @if($restoreCoupon)<p class="admin-operations-note">本次输入的券码未保留；{{ $editing ? '当前显示已保存券码，如需修改请重新输入。' : '请重新输入券码。' }}</p>@endif
        <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/config/coupon">@csrf
            <input type="hidden" name="_admin_form" value="coupon"><input type="hidden" name="id" value="{{ $editing?->id }}">
            <label>券码<input name="code" required maxlength="64" value="{{ $couponValue('code', $editing?->code) }}"></label>
            <label>类型<select name="type">@foreach(['percent' => '百分比', 'fixed' => '固定金额'] as $type => $label)<option value="{{ $type }}" @selected($couponValue('type', $editing?->type ?? 'percent') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label>优惠值<input name="value" required value="{{ $couponValue('value', $editing?->value ?? '10.00') }}"></label>
            <label>最低金额<input name="min_order_amount" required value="{{ $couponValue('min_order_amount', $editing?->min_order_amount ?? '0.00') }}"></label>
            <label>适用账单<select name="applies_to">@foreach(['both' => '新购及续费', 'new_order' => '仅新购', 'renew' => '仅续费'] as $type => $label)<option value="{{ $type }}" @selected($couponValue('applies_to', $editing?->applies_to ?? 'both') === $type)>{{ $label }}</option>@endforeach</select></label>
            <label>总额度（空为不限）<input type="number" name="total_quota" min="0" value="{{ $couponValue('total_quota', $editing?->total_quota) }}"></label>
            <label>每人限次<input type="number" name="per_user_limit" min="1" required value="{{ $couponValue('per_user_limit', $editing?->per_user_limit ?? 1) }}"></label>
            <label>状态<select name="is_active"><option value="1" @selected((string) $couponValue('is_active', $editing?->is_active ?? true) === '1')>启用</option><option value="0" @selected((string) $couponValue('is_active', $editing?->is_active ?? true) !== '1')>停用</option></select></label>
            <label>生效时间<input type="datetime-local" name="valid_from" value="{{ $couponValue('valid_from', $editing?->valid_from?->format('Y-m-d\TH:i')) }}"></label>
            <label>截止时间<input type="datetime-local" name="valid_until" value="{{ $couponValue('valid_until', $editing?->valid_until?->format('Y-m-d\TH:i')) }}"></label>
            @include('filament.operations-product-selector', ['selectedProductIds' => $editing?->product_ids, 'restoreOldInputs' => $restoreCoupon])
            @php
                $claimConditions = $couponValue('claim_conditions', $editing?->claim_conditions ?? []);
                $requiredChannels = data_get($claimConditions, 'require_bound_channels', []);
            @endphp
            <details class="admin-operations-fieldset admin-operations-wide"><summary>领取条件（可选）</summary>
                <div class="inventory-form">
                    <label>注册天数大于<input name="claim_conditions[registered_days_gt]" type="number" min="0" value="{{ data_get($claimConditions, 'registered_days_gt') }}" placeholder="不限"></label>
                    <label>注册天数小于<input name="claim_conditions[registered_days_lt]" type="number" min="1" value="{{ data_get($claimConditions, 'registered_days_lt') }}" placeholder="不限"></label>
                    <input type="hidden" name="claim_conditions[require_kyc]" value="0"><label><span><input type="checkbox" name="claim_conditions[require_kyc]" value="1" @checked(data_get($claimConditions, 'require_kyc', false))> 须完成实名认证</span></label>
                    <fieldset><legend>须绑定的联系方式</legend>@foreach(['email' => '邮箱', 'sms' => '手机', 'telegram' => 'Telegram'] as $channel => $channelLabel)<label><span><input type="checkbox" name="claim_conditions[require_bound_channels][]" value="{{ $channel }}" @checked(is_array($requiredChannels) && in_array($channel, $requiredChannels, true))> {{ $channelLabel }}</span></label>@endforeach</fieldset>
                </div>
            </details>
            <label class="admin-operations-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $couponValue('reason') }}"></label>
            <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">保存</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    <x-admin.dialog id="coupon-grant" title="定向发放领取资格" description="发放领取资格不增加优惠券核销次数。" :show-errors="$restoreGrant" x-init="{{ $restoreGrant ? '$nextTick(() => $el.showModal())' : '' }}">
        @if($restoreGrant)<p class="admin-operations-note">本次输入的券码未保留，请重新输入券码。</p>@endif
        <form class="inventory-form admin-operations-form" method="post" action="/admin/operations/coupons/grant">@csrf<input type="hidden" name="_admin_form" value="coupon-grant">
            <label>用户 ID<input name="user_id" type="number" min="1" required value="{{ $restoreGrant ? old('user_id') : '' }}"></label>
            <label>券码<input name="code" required maxlength="64" value="{{ $restoreGrant ? old('code') : '' }}"></label>
            <label class="admin-operations-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $restoreGrant ? old('reason') : '' }}"></label>
            <div class="admin-operations-form-actions admin-operations-wide"><button class="admin-ui-button admin-ui-button-primary">发放</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
@else
    @if($editing)<x-admin.card :title="'优惠券 #'.$editing->id" description="当前为只读查看。"><div class="admin-ui-card-body"><dl class="admin-ui-facts"><div><dt>券码</dt><dd>{{ $editing->code }}</dd></div><div><dt>适用账单</dt><dd>{{ $editing->applies_to }}</dd></div><div><dt>每人限次</dt><dd>{{ $editing->per_user_limit }}</dd></div><div><dt>生效时间</dt><dd>{{ $editing->valid_from ?? '不限' }}</dd></div><div><dt>截止时间</dt><dd>{{ $editing->valid_until ?? '不限' }}</dd></div></dl></div></x-admin.card>@endif
@endcan
