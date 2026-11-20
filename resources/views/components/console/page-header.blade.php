@props(['title', 'description' => null])
<header {{ $attributes->class(['console-page-header']) }}>
    <div><h1>{{ $title }}</h1>@if($description)<p>{{ $description }}</p>@endif</div>
    @isset($actions)<div class="console-actions">{{ $actions }}</div>@endisset
</header>
