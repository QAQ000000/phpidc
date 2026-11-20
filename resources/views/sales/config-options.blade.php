@php
    $configurationGroups = $product->optionGroups()->where('is_hidden', false)->where('sync_source', 'upstream')->with(['items' => fn ($q) => $q->where('is_hidden', false)->orderBy('sort_order')->orderBy('id')])->orderBy('sort_order')->orderBy('id')->get();
    $hasLinkage = $configurationGroups->contains(fn ($group) => (int) ($group->upstream_meta['option_type'] ?? 0) === 20);
@endphp
@if($hasLinkage && ($serviceChange ?? false))<p role="alert">此服务含层级联动配置，当前暂不支持升级、降配和绑定后的价格复核。</p>@endif
@if($hasLinkage && !($serviceChange ?? false))<noscript><p role="alert">此商品使用层级联动配置，请启用 JavaScript 后选择完整配置和购买。</p></noscript>@endif
@foreach($configurationGroups as $group)
@php
    $isLinkage = (int) ($group->upstream_meta['option_type'] ?? 0) === 20;
    $linkageSubs = $isLinkage ? collect($group->upstream_meta['subs'] ?? [])->keyBy('id') : collect();
    $linkageParents = $isLinkage ? $linkageSubs->pluck('linkage_pid')->map(fn ($id) => (int) $id)->unique()->values()->all() : [];
@endphp
@if($isLinkage)<div data-linkage-group="{{ $group->upstream_group_id }}" hidden>@endif
<label>{{ $group->name }} @if($group->unit)({{ $group->unit }})@endif
@if(in_array($group->type, ['quantity','slider'], true))
@php($stage = $group->upstream_meta['qty_stage'] ?? null)
<input type="number" name="options[configoption][{{ $group->upstream_group_id }}]" data-option="{{ $group->upstream_group_id }}" data-store-option-label="{{ $group->name }}" data-option-type="{{ $group->type }}" @if($stage !== null)data-qty-stage="{{ max(1, (int) $stage) }}"@endif min="{{ $group->min_value }}" max="{{ $group->max_value }}" step="{{ $stage !== null ? 1 : $group->step }}" value="{{ $selection['options']['configoption'][$group->upstream_group_id] ?? $group->min_value }}" required>
@if((int) $stage > 1)<small>数量须为 {{ (int) $stage }} 的倍数，或选择最小值 {{ $group->min_value }}、最大值 {{ $group->max_value }}。</small>@endif
@else
<select name="options[configoption][{{ $group->upstream_group_id }}]" data-option="{{ $group->upstream_group_id }}" data-store-option-label="{{ $group->name }}" data-option-type="{{ $isLinkage ? 'linkage' : $group->type }}" @if($isLinkage)data-linkage-parent="{{ (int) ($group->upstream_meta['linkage_pid'] ?? 0) }}" data-linkage-parents="{{ json_encode($linkageParents) }}" @endif @disabled($isLinkage) required>
@if($isLinkage)<option value="">请选择</option>@endif
@if($group->type === 'toggle')<option value="0" @selected((string) ($selection['options']['configoption'][$group->upstream_group_id] ?? '') === '0')>不启用</option>@endif
@foreach($group->items as $item)<option value="{{ $item->upstream_sub_id }}" @if($isLinkage)data-linkage-parent="{{ (int) ($linkageSubs->get($item->upstream_sub_id)['linkage_pid'] ?? -1) }}"@endif data-cycles="{{ json_encode(array_keys(array_filter($item->upstream_base_prices ?? [], fn ($price) => $price['enabled'] ?? false))) }}" @selected((string) ($selection['options']['configoption'][$group->upstream_group_id] ?? '') === (string) $item->upstream_sub_id)>{{ $item->label }}</option>@endforeach
</select>
@endif</label>
@if($isLinkage)</div>@endif
@endforeach
@unless($serviceChange ?? false)
@foreach($product->customFields()->where('is_hidden', false)->where('show_on_order', true)->where('sync_source','upstream')->get() as $field)
<label>{{ $field->name }} @if($field->is_required)*@endif
@if($field->field_type === 'select')<select name="options[customfield][{{ $field->upstream_field_id }}]" @required($field->is_required)>@foreach($field->options ?? [] as $option)<option value="{{ $option }}" @selected(($selection['options']['customfield'][$field->upstream_field_id] ?? '') === $option)>{{ $option === '' ? '未选择' : $option }}</option>@endforeach</select>
@elseif($field->field_type === 'textarea')<textarea name="options[customfield][{{ $field->upstream_field_id }}]" maxlength="4096" @required($field->is_required)>{{ $selection['options']['customfield'][$field->upstream_field_id] ?? '' }}</textarea>
@else<input type="{{ $field->field_type === 'password' ? 'password' : 'text' }}" name="options[customfield][{{ $field->upstream_field_id }}]" maxlength="4096" @required($field->is_required) autocomplete="off" @if($field->field_type !== 'password')value="{{ $selection['options']['customfield'][$field->upstream_field_id] ?? '' }}"@endif>
@endif</label>
@endforeach
@endunless
