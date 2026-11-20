@props(['tone' => 'neutral'])
<span {{ $attributes->class(['console-badge', 'is-'.(in_array($tone, ['neutral', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral')]) }}>{{ $slot }}</span>
