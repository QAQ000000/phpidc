<x-filament-panels::page>
@include('filament.inventory-feedback')
@foreach($accounts as $account)<section class="inventory-box"><h2>{{ $account->name }} #{{ $account->id }}</h2><p>{{ $account->base_url }} · {{ $account->is_active ? '启用' : '停用' }}</p>
<form class="inventory-form" action="/admin/upstreams/catalog" method="post">@csrf<input type="hidden" name="account_id" value="{{ $account->id }}"><button class="inventory-button">读取上游商品目录</button></form></section>@endforeach
@can('upstream.configure')<details class="inventory-box"><summary>新增 / 更新连接</summary><p>使用同一名称更新连接；更新后应重启常驻 worker。密钥输入客户端 API 密钥。</p>
<form class="inventory-form" method="post" action="/admin/upstreams/configure">@csrf<label>连接名称<input name="name" required maxlength="255"></label><label>上游地址<input name="url" type="url" required></label><label>客户账号<input name="username" required></label><label>API 密钥<input type="password" name="api_secret" required autocomplete="new-password"></label>
<label><input type="checkbox" name="raw_jwt" value="1">使用原始 JWT 兼容模式</label><label><input type="checkbox" name="credit_limit" value="1">允许信用额度支付回退（会产生上游债务）</label><label>原因<input name="reason" required></label><button class="inventory-button">保存加密连接</button></form></details>@endcan
@can('upstream.import')<details class="inventory-box"><summary>导入商品与完整配置</summary><form class="inventory-form" method="post" action="/admin/upstreams/import">@csrf<label>连接<select name="account_id">@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label><label>上游商品 ID<input name="pid" type="number" min="1" required></label><label>初始周期<select name="cycle">@foreach(\App\Enums\BillingCycle::cases() as $cycle)<option value="{{ $cycle->value }}" @selected($cycle->value === 'monthly')>{{ $cycle->label() }}</option>@endforeach</select></label><label>币种<input name="currency" value="CNY" pattern="[A-Z]{3}" required></label><label>加价百分比<input name="markup" value="20.00" required></label><label>原因<input name="reason" required></label><button class="inventory-button">导入并同步</button></form></details>@endcan
@foreach($products as $product)<section class="inventory-box"><h2>{{ $product->name }} #{{ $product->id }}</h2><p>连接 #{{ $product->upstream_api_id }} / 商品 #{{ $product->upstream_pid }} / 版本 {{ $product->upstream_version }} / {{ $product->currency }}</p>
<a href="/console/products/{{ $product->id }}/configure">查看配置界面</a>
@can('upstream.import')<form class="inventory-form" method="post" action="/admin/upstreams/{{ $product->id }}/sync">@csrf<label>同步原因<input name="reason" required></label><button class="inventory-button">同步配置与价格</button></form>@endcan
<ul>@foreach($product->optionGroups as $group)<li>{{ $group->name }} · {{ $group->type }} · {{ $group->is_hidden ? '隐藏' : '显示' }}<ul>@foreach($group->items as $item)<li>#{{ $item->id }} {{ $item->label }}{{ $item->is_hidden ? '（已下架）' : '' }}</li>@endforeach</ul>
@can('upstream.import')<details class="inventory-box"><summary>未来续费降价边界（远程配置不变）</summary>
<form class="inventory-form" method="post" action="/admin/upstream-options/{{ $group->id }}/bounds">@csrf
<label><input type="checkbox" name="allow" value="1" @checked($group->downgrade_bounds['allow_downgrade'] ?? false)>允许降低未来续费价</label>
@if(in_array($group->type, ['quantity','slider'], true))<label>最低数量<input type="number" name="minimum" min="{{ $group->min_value }}" max="{{ $group->max_value }}" step="{{ $group->step }}" value="{{ $group->downgrade_bounds['min_value'] ?? $group->min_value }}"></label>
@else @foreach($group->items->where('is_hidden', false) as $item)<label><input type="checkbox" name="items[]" value="{{ $item->id }}" @checked(in_array($item->id, $group->downgrade_bounds['allowed_item_ids'] ?? [], true))>允许 {{ $item->label }}</label>@endforeach @endif
<label>原因<input name="reason" required></label><button class="inventory-button">保存降价边界</button></form></details>@endcan
</li>@endforeach</ul></section>@endforeach
{{ $products->links() }}
</x-filament-panels::page>
