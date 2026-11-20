@if($paginator->hasPages())
<nav class="store-pagination" role="navigation" aria-label="分页">
    <span class="store-pagination-count">共 {{ $paginator->total() }} 条 · 第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 页</span>
    <div>
        @if($paginator->onFirstPage())<span aria-disabled="true">上一页</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>@endif
        @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>@else<span aria-disabled="true">下一页</span>@endif
    </div>
</nav>
@endif
