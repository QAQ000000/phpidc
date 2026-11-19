<x-admin.dialog id="upstream-connections" title="上游连接管理" description="管理接口账户，已有连接密钥留空时保留原值。" :show-errors="$reopenDialog === 'upstream-connections'">
    <h3>经典 ZJMF 商品与连接</h3>
    @forelse($accounts as $account)
        <section class="inventory-box">
            <div class="admin-ui-option-heading"><h3>{{ $account->name }} <span class="admin-ui-meta">#{{ $account->id }}</span></h3><x-admin.badge :tone="$account->is_active ? 'success' : 'neutral'">{{ $account->is_active ? '启用' : '停用' }}</x-admin.badge></div>
            <p class="inventory-note">{{ $account->base_url }}</p>
            <form class="inventory-form" action="/admin/upstreams/catalog" method="post">@csrf<input type="hidden" name="account_id" value="{{ $account->id }}"><button class="inventory-button">读取上游商品目录</button></form>
            @can('upstream.configure')<details @if(old('ui_form') === 'classic-'.$account->id) open @endif><summary>编辑此连接</summary>@include('filament.upstream-connection-form', ['account' => $account])</details>@endcan
        </section>
    @empty<p class="inventory-note">尚未创建经典魔方连接。</p>@endforelse
    @can('upstream.configure')<details class="inventory-box" @if(old('ui_form') === 'classic-new') open @endif><summary>新增连接</summary><p class="inventory-note">密钥输入客户端 API 密钥。已有连接请使用上方的编辑入口。</p>@include('filament.upstream-connection-form', ['account' => null])</details>@endcan
    <h3>Sub2API 远程余额兑换码连接</h3>
    @forelse($sub2Accounts as $account)<section class="inventory-box">
        <div class="admin-ui-option-heading"><h3>{{ $account->name }} <span class="admin-ui-meta">#{{ $account->id }}</span></h3><x-admin.badge :tone="$account->is_active ? 'success' : 'neutral'">{{ $account->is_active ? '启用' : '停用' }}</x-admin.badge></div><p class="inventory-note">{{ $account->base_url }}</p>
        @can('upstream.configure')<details @if(old('ui_form') === 'sub2api-'.$account->id) open @endif><summary>编辑 Sub2API 连接</summary>@include('filament.sub2api-connection-form', ['account' => $account])</details>@endcan
    </section>@empty<p class="inventory-note">尚未创建 Sub2API 连接。</p>@endforelse
    @can('upstream.configure')<details class="inventory-box" @if(old('ui_form') === 'sub2api-new') open @endif><summary>新增 Sub2API 连接</summary><p class="inventory-note">接入支持余额兑换码的 Sub2API 站点，管理员密钥仅加密保存。</p>@include('filament.sub2api-connection-form', ['account' => null])</details>@endcan
</x-admin.dialog>
@can('upstream.import')
    <x-admin.dialog id="upstream-import" title="导入商品与完整配置" description="按上游商品 ID 导入基础信息、配置与本地价格。" :show-errors="$reopenDialog === 'upstream-import'">
        @if($accounts->isEmpty())<p>请先在上游连接管理中新增经典魔方连接。</p>
        @else
            @php
                $restoreImport = old('ui_form') === 'classic-import';
                $importValue = static fn (string $key, string $default = ''): string => $restoreImport && is_scalar(old($key, $default)) ? (string) old($key, $default) : $default;
            @endphp
            <form class="inventory-form" method="post" action="/admin/upstreams/import">@csrf
                <input type="hidden" name="ui_dialog" value="upstream-import"><input type="hidden" name="ui_form" value="classic-import">
                <label>连接<select name="account_id">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($importValue('account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
                <label>上游商品 ID<input name="pid" type="number" min="1" value="{{ $importValue('pid') }}" required></label>
                <label>初始周期<select name="cycle">@foreach(\App\Enums\BillingCycle::cases() as $cycle)<option value="{{ $cycle->value }}" @selected($cycle->value === $importValue('cycle', 'monthly'))>{{ $cycle->label() }}</option>@endforeach</select></label>
                <label>币种<input name="currency" value="{{ $importValue('currency', 'CNY') }}" pattern="[A-Z]{3}" required></label><label>加价百分比<input name="markup" value="{{ $importValue('markup', '20.00') }}" required></label>
                <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000" value="{{ $importValue('reason') }}"></label></details><button class="inventory-button">导入并同步</button>
            </form>
        @endif
    </x-admin.dialog>
@endcan
