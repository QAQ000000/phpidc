@props(['action'])
<form method="get" action="{{ $action }}" {{ $attributes->class(['admin-ui-filters']) }} aria-label="筛选条件">
    {{ $slot }}
    <div class="admin-ui-filter-actions"><button type="submit" class="admin-ui-button admin-ui-button-primary">查询</button><a href="{{ $action }}" class="admin-ui-button">重置</a></div>
</form>
