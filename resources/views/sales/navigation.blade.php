<nav class="console-navigation" aria-label="客户功能">
    @foreach($navigationGroups as $group)
        @if($group['label'] !== null)
        <details class="console-nav-group" @if(collect($group['items'])->contains('active', true)) open @endif>
            <summary>{{ $group['label'] }}<span class="console-nav-chevron" aria-hidden="true"></span></summary>
        @else
        <div class="console-nav-primary">
        @endif
        @foreach($group['items'] as $item)
            <a href="{{ $item['url'] }}" class="console-nav-link {{ $item['active'] ? 'is-active' : '' }}" @if($item['active']) aria-current="page" @endif><span class="console-nav-icon" aria-hidden="true" style="--console-icon:url('data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="'.$item['icon'].'"/></svg>') }}')"></span><span>{{ $item['label'] }}</span></a>
        @endforeach
        @if($group['label'] !== null)</details>@else</div>@endif
    @endforeach
</nav>
