<x-filament-panels::page>
@include('filament.admin-ui-styles')
<div class="admin-ui">
@include('filament.inventory-feedback')
<p><a class="admin-ui-link" href="/admin/upstreams">返回商品导入</a></p>
@forelse($groups as $group)
<x-admin.card :title="$group['name']">
    <x-admin.table :label="$group['name'].'商品目录'">
        <x-slot:head><tr><th scope="col">上游商品 ID</th><th scope="col">商品名称</th>@can('upstream.import')<th scope="col">操作</th>@endcan</tr></x-slot:head>
        @forelse($group['products'] as $product)
            <tr><td>#{{ $product['id'] }}</td><td>{{ $product['name'] }}</td>
            @can('upstream.import')
                <td><details><summary>导入此商品</summary>
                    <form class="inventory-form" method="post" action="/admin/upstreams/import">@csrf
                        <input type="hidden" name="ui_dialog" value="upstream-import"><input type="hidden" name="ui_form" value="classic-import">
                        <input type="hidden" name="account_id" value="{{ $accountId }}"><input type="hidden" name="pid" value="{{ $product['id'] }}">
                        <label>初始周期<select name="cycle">@foreach(\App\Enums\BillingCycle::cases() as $cycle)<option value="{{ $cycle->value }}" @selected($cycle->value === 'monthly')>{{ $cycle->label() }}</option>@endforeach</select></label>
                        <label>币种<input name="currency" value="CNY" pattern="[A-Z]{3}" required></label>
                        <label>加价百分比<input name="markup" value="20.00" required></label>
                        <details><summary>添加备注（选填）</summary><label>备注<input name="reason" maxlength="1000"></label></details>
                        <button class="admin-ui-button admin-ui-button-primary">导入并同步此商品</button>
                    </form>
                </details></td>
            @endcan
            </tr>
        @empty
            <tr><td colspan="{{ auth()->user()->can('upstream.import') ? 3 : 2 }}">此分组暂无商品。</td></tr>
        @endforelse
    </x-admin.table>
</x-admin.card>
@empty
    <x-admin.card title="商品目录"><p class="admin-ui-empty">上游目录没有商品。</p></x-admin.card>
@endforelse
</div>
</x-filament-panels::page>
