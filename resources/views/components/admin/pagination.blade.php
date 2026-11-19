@props(['paginator'])
<div class="admin-ui-pagination">
    <p>共 <strong>{{ $paginator->total() }}</strong> 条@if($paginator->count() > 0)，当前 {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} 条@endif</p>
    @if($paginator->hasPages())
        <nav aria-label="结果分页">
            @if($paginator->onFirstPage())<span aria-disabled="true">上一页</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>@endif
            @php
                $previous = 0;
                $windowPage = min($paginator->lastPage(), $paginator->currentPage());
            @endphp
            @foreach(array_unique([1, ...range(max(1, $windowPage - 2), min($paginator->lastPage(), $windowPage + 2)), $paginator->lastPage()]) as $page)
                @if($page > $previous + 1)<span aria-hidden="true">…</span>@endif
                @if($page === $paginator->currentPage())<span aria-current="page">{{ $page }}</span>@else<a href="{{ $paginator->url($page) }}" aria-label="第 {{ $page }} 页">{{ $page }}</a>@endif
                @php($previous = $page)
            @endforeach
            @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>@else<span aria-disabled="true">下一页</span>@endif
        </nav>
    @endif
</div>
