@props(['name', 'identity', 'status', 'tone' => 'neutral', 'description' => null, 'returnUrl', 'returnLabel'])
<header {{ $attributes->class(['admin-ui-heading', 'admin-workspace-header']) }} data-workspace-header>
    <div class="admin-workspace-identity">
        <div class="admin-workspace-title"><h2 data-workspace-name>{{ $name }}</h2><span class="admin-workspace-id">{{ $identity }}</span><x-admin.badge :tone="$tone">{{ $status }}</x-admin.badge>{{ $badges ?? '' }}</div>
        @if($description)<p>{{ $description }}</p>@endif
    </div>
    <div class="admin-ui-actions admin-workspace-actions">
        <a class="admin-ui-button" href="{{ $returnUrl }}" data-workspace-return>{{ $returnLabel }}</a>
        {{ $slot }}
    </div>
</header>
