@foreach($product->optionGroups()->where('is_hidden', false)->where('sync_source','upstream')->with(['items' => fn ($q) => $q->where('is_hidden', false)->orderBy('sort_order')])->orderBy('sort_order')->get() as $group)
<label>{{ $group->name }} @if($group->unit)({{ $group->unit }})@endif
@if(in_array($group->type, ['quantity','slider'], true))
<input type="number" name="options[configoption][{{ $group->upstream_group_id }}]" data-option="{{ $group->upstream_group_id }}" min="{{ $group->min_value }}" max="{{ $group->max_value }}" step="{{ $group->step }}" value="{{ $selection['options']['configoption'][$group->upstream_group_id] ?? $group->min_value }}" required>
@else
<select name="options[configoption][{{ $group->upstream_group_id }}]" data-option="{{ $group->upstream_group_id }}" required>
@if($group->type === 'toggle')<option value="0" @selected((string) ($selection['options']['configoption'][$group->upstream_group_id] ?? '') === '0')>不启用</option>@endif
@foreach($group->items as $item)<option value="{{ $item->upstream_sub_id }}" data-cycles="{{ json_encode(array_keys(array_filter($item->upstream_base_prices ?? [], fn ($price) => $price['enabled'] ?? false))) }}" @selected((string) ($selection['options']['configoption'][$group->upstream_group_id] ?? '') === (string) $item->upstream_sub_id)>{{ $item->label }}</option>@endforeach
</select>
@endif</label>
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
