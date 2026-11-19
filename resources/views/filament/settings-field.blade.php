@php
    $fieldId = 'setting-'.str_replace('.', '-', $fieldKey);
    $hint = $field['help'] ?? ($fieldHints[$fieldKey] ?? '');
    $secret = $field['secret'] ?? false;
    $value = $values[$fieldKey]['value'];
    $enabled = in_array($value, ['1', 'true'], true);
@endphp
<div class="settings-field">
    <dt>
        @if($editable)<label for="{{ $fieldId }}">{{ $field['label'] }}</label>@else{{ $field['label'] }}@endif
        @if($secret && $editable)<span class="settings-field-badge">{{ $values[$fieldKey]['configured'] ? '已配置' : '未配置' }}</span>@endif
    </dt>
    <dd class="settings-control">
        @if(! $editable)
            <span class="settings-read-value @if($field['type'] === 'textarea') settings-read-multiline @endif">{{ $secret ? ($values[$fieldKey]['configured'] ? '已配置' : '未配置') : ($field['type'] === 'checkbox' ? ($enabled ? '启用' : '停用') : ($field['type'] === 'select' ? ($field['options'][$value] ?? $value) : ($value !== '' ? $value : '未配置'))) }}</span>
        @elseif($field['type'] === 'checkbox')
            <input type="hidden" name="settings[{{ $fieldKey }}]" value="0">
            <label class="settings-switch" for="{{ $fieldId }}">
                <input id="{{ $fieldId }}" type="checkbox" role="switch" name="settings[{{ $fieldKey }}]" value="1" @checked($enabled) @if($hint !== '') aria-describedby="{{ $fieldId }}-help" @endif>
                <span class="settings-switch-track" aria-hidden="true"></span>
                <span class="settings-switch-on">已启用</span><span class="settings-switch-off">已停用</span>
            </label>
        @elseif($field['type'] === 'select')
            <select id="{{ $fieldId }}" name="settings[{{ $fieldKey }}]" @if($hint !== '') aria-describedby="{{ $fieldId }}-help" @endif>@foreach($field['options'] as $option => $label)<option value="{{ $option }}" @selected($value === (string) $option)>{{ $label }}</option>@endforeach</select>
        @elseif($field['type'] === 'textarea')
            <textarea id="{{ $fieldId }}" name="settings[{{ $fieldKey }}]" rows="{{ $fieldKey === 'sms.idcsmart_templates' ? 6 : 3 }}" @if($fieldKey === 'sms.idcsmart_templates') class="settings-code-input" spellcheck="false" @endif @if($hint !== '') aria-describedby="{{ $fieldId }}-help" @endif>{{ $value }}</textarea>
        @elseif($secret)
            <input id="{{ $fieldId }}" type="password" name="settings[{{ $fieldKey }}]" value="" autocomplete="new-password" placeholder="{{ $values[$fieldKey]['configured'] ? '留空保留现有凭据' : '尚未配置，请填写' }}" @if($hint !== '') aria-describedby="{{ $fieldId }}-help" @endif>
        @else
            <input id="{{ $fieldId }}" type="{{ $fieldKey === 'mail.from_address' ? 'email' : $field['type'] }}" name="settings[{{ $fieldKey }}]" value="{{ $value }}" @if($fieldKey === 'mail.port') min="1" max="65535" step="1" @endif @if(isset($field['min'])) min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="1" @endif @if($hint !== '') aria-describedby="{{ $fieldId }}-help" @endif>
        @endif
        @if($hint !== '')<p class="settings-field-help" id="{{ $fieldId }}-help">{{ $hint }}</p>@endif
    </dd>
</div>
