@props(['title' => null, 'description' => null])
<section {{ $attributes->class(['console-card']) }}>
    @if($title || isset($actions))<header class="console-card-header"><div>@if($title)<h2>{{ $title }}</h2>@endif @if($description)<p>{{ $description }}</p>@endif</div>@isset($actions)<div class="console-actions">{{ $actions }}</div>@endisset</header>@endif
    <div class="console-card-body">{{ $slot }}</div>
</section>
