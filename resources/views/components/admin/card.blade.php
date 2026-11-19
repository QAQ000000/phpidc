@props(['title' => null, 'description' => null])
<section {{ $attributes->class(['admin-ui-card']) }}>
    @if($title || isset($actions))
        <header class="admin-ui-card-heading">
            <div>@if($title)<h2>{{ $title }}</h2>@endif @if($description)<p>{{ $description }}</p>@endif</div>
            @isset($actions)<div class="admin-ui-actions">{{ $actions }}</div>@endisset
        </header>
    @endif
    {{ $slot }}
</section>
