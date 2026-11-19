@props(['tone' => 'neutral'])
<span {{ $attributes->class(['admin-ui-badge', 'admin-ui-badge-'.(in_array($tone, ['success', 'warning', 'danger'], true) ? $tone : 'neutral')]) }}>{{ $slot }}</span>
