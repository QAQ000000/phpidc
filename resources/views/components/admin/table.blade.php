@props(['label'])
<div class="admin-ui-table-scroll" role="region" aria-label="{{ $label }}" tabindex="0">
    <table {{ $attributes->class(['admin-ui-table']) }}>
        <caption class="admin-ui-sr-only">{{ $label }}</caption>
        <thead>{{ $head }}</thead><tbody>{{ $slot }}</tbody>
    </table>
</div>
