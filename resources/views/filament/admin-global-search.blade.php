@if(auth()->check() && auth()->user()->canAny(['customers.view', 'finance.view', 'upstream.view']))
    @once
        <link rel="stylesheet" href="/css/admin-search.css?v=20261120c1">
        <script src="/js/admin-search.js?v=20261120c1" defer></script>
    @endonce
    <div class="admin-search" data-admin-search>
        <button type="button" class="admin-search-trigger" data-search-open aria-label="打开后台全局搜索" aria-haspopup="dialog" aria-controls="admin-global-search-dialog">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>
            <span>搜索客户、账单、服务</span><kbd>Ctrl K</kbd>
        </button>
        <dialog id="admin-global-search-dialog" class="admin-search-dialog" aria-labelledby="admin-search-title" data-search-dialog>
            <div class="admin-search-heading"><h2 id="admin-search-title">后台全局搜索</h2><button type="button" class="admin-search-close" data-search-close aria-label="关闭搜索">✕</button></div>
            <form class="admin-search-form" data-search-form role="search" action="/admin/search" method="get">
                <label for="admin-search-query">客户 ID / 完整邮箱、账单 ID / 完整账单号、服务 ID / 完整上游 ID</label>
                <div class="admin-search-input-row"><input id="admin-search-query" name="q" type="search" maxlength="255" placeholder="输入完整编号或邮箱" autocomplete="off" spellcheck="false" data-search-input><button type="submit">搜索</button></div>
            </form>
            <p class="admin-search-status" data-search-status role="status" aria-live="polite">仅搜索你有权限查看的数据。</p>
            <div class="admin-search-results" data-search-results aria-label="搜索结果" aria-busy="false"></div>
            <p class="admin-search-help">每类最多 5 条；用 ↑ ↓ 选择结果，Enter 打开，Esc 关闭。</p>
        </dialog>
    </div>
@endif
