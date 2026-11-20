<x-filament-panels::page>
@include('filament.admin-ui-styles')
<div class="admin-ui">
    @include('filament.inventory-feedback')
    <header class="admin-ui-heading">
        <div><h2>{{ $invoice->invoice_no }}</h2><p>账单核查与退款 · 按原账单记录核对资金分配。</p></div>
        <div class="admin-ui-actions"><a class="admin-ui-button" href="{{ $returnUrl }}">返回账单</a><a class="admin-ui-button" href="/admin/finance/ledger?invoice_id={{ $invoice->id }}">查看关联流水和回调</a>@if($invoice->type !== \App\Enums\InvoiceType::WALLET_TOPUP)@can('tasks.view')<a class="admin-ui-button" href="/admin/tasks?invoice_id={{ $invoice->id }}">关联任务</a>@endcan @endif</div>
    </header>
    <x-admin.card title="账单信息">
        <div class="admin-ui-card-body">
            <dl class="admin-ui-facts">
                <div><dt>账单 / 客户</dt><dd>#{{ $invoice->id }} · 客户 #{{ $invoice->user_id }}</dd></div>
                <div><dt>类型 / 状态</dt><dd>{{ $typeLabels[$invoice->type->value] }} <x-admin.badge :tone="match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : $statusLabels[$invoice->status->value] }}</x-admin.badge><span class="admin-ui-meta">{{ $invoice->type->value }} · {{ $invoice->status->value }}</span></dd></div>
                <div><dt>商品 / 币种</dt><dd>{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值' : '商品 #'.($invoice->product_id ?? '—') }} · {{ $invoice->currency }}</dd></div>
                <div><dt>关联服务</dt><dd>@if($instance)#{{ $instance->id }} {{ $instance->display_name }} · {{ $instance->status->value }}@else 无关联服务@endif</dd></div>
                <div><dt>支付时间</dt><dd>{{ $invoice->paid_at ?? '—' }}</dd></div>
                <div><dt>账单到期</dt><dd>{{ $invoice->expires_at ?? '—' }}</dd></div>
                <div><dt>创建时间</dt><dd>{{ $invoice->created_at ?? '—' }}</dd></div>
                <div><dt>支付网关</dt><dd>{{ $invoice->gateway ?? '—' }}</dd></div>
            </dl>
        </div>
    </x-admin.card>
    <x-admin.card title="金额与分配" description="优惠已计入账单总额，不重复扣减。">
        <div class="admin-ui-card-body">
            <p>账单总额 {{ $invoice->total_amount }} = 钱包 {{ $invoice->credit_applied }} + 网关 {{ $invoice->gateway_amount }}</p>
            <p class="inventory-note">优惠 {{ $invoice->discount_amount }}（已计入总额，不再减一次） · 预计上游成本 {{ $invoice->upstream_expected_cost ?? '—' }} · {{ $invoice->currency }}</p>
        </div>
    </x-admin.card>
    @can('refund-invoice', $invoice)
        @php($refundInput = old('ui_form') === 'refund-'.$invoice->id)
        @if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)
            <x-admin.card title="充值到账核查"><div class="admin-ui-card-body"><p>充值账单只核查到账及对应流水，暂不支持充值退款。</p></div></x-admin.card>
        @elseif($invoice->status === \App\Enums\InvoiceStatus::PAID)
            <x-admin.card :title="'全额退款 '.$invoice->total_amount.' '.$invoice->currency">
                <div class="admin-ui-card-body">
                    <p class="inventory-note">先确认服务已终止。若明确豁免，必须单独勾选保留服务；已交付卡密不会回收，退款会记录损失。佣金不足扣回的差额登记应收，仍需人工处理。</p>
                    <details @if($refundInput && $errors->any()) open @endif><summary>核对并填写全额退款</summary>
                        <form method="post" action="/admin/finance/invoices/{{ $invoice->id }}/refund" class="inventory-form" x-data="{ mode: '{{ $refundInput && old('mode') === 'external_record' ? 'external_record' : 'refund_wallet' }}', keepService: {{ ($instance === null || !$instance->status->isTerminal()) && $refundInput && old('keep_service') === '1' ? 'true' : 'false' }} }">@csrf
                            <input type="hidden" name="ui_form" value="refund-{{ $invoice->id }}">
                            <label>处理方式<select name="mode" required x-model="mode"><option value="refund_wallet" @selected(!$refundInput || old('mode') !== 'external_record')>退回客户钱包</option><option value="external_record" @selected($refundInput && old('mode') === 'external_record')>登记已完成的外部退款</option></select></label>
                            <label>{{ $instance !== null && $instance->status->isTerminal() ? '备注（选填）' : '退款说明（保留服务时必填）' }}<input name="reason" value="{{ $refundInput && is_string(old('reason')) ? old('reason') : '' }}" :required="keepService" maxlength="255" placeholder="有特殊情况时补充说明"></label>
                            @if($instance === null || !$instance->status->isTerminal())<label><span><input type="checkbox" name="keep_service" value="1" x-model="keepService"> 明确豁免终止前置条件，接受保留服务的损失</span></label>@endif
                            <label x-show="mode === 'external_record'"><span><input type="checkbox" name="external_confirm" value="1" :disabled="mode !== 'external_record'" :required="mode === 'external_record'"> 外部退款已经完成（本系统不打款）</span></label>
                            <label><span><input type="checkbox" name="confirm" value="1" required> 已核对客户、全额金额、服务状态及退款方式</span></label>
                            <button class="inventory-button">确认全额退款</button>
                        </form>
                    </details>
                </div>
            </x-admin.card>
        @else
            <x-admin.card title="退款状态"><div class="admin-ui-card-body"><p>当前状态不可发起新退款。已退款账单可在流水中核对唯一退款记录。</p></div></x-admin.card>
        @endif
    @endcan
    <x-admin.card title="支付尝试" description="每次发起支付使用独立订单号，按最新记录倒序展示。">
        <x-admin.table label="支付尝试">
            <x-slot:head><tr><th scope="col">ID / 网关</th><th scope="col">精确订单号</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">状态 / 支付时间</th></tr></x-slot:head>
            @forelse($attempts as $attempt)<tr><td>#{{ $attempt->id }} <span class="admin-ui-meta">{{ $attempt->gateway }}</span></td><td>{{ $attempt->out_trade_no }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $attempt->amount }} {{ $invoice->currency }}</td><td><x-admin.badge :tone="$attempt->status === 'paid' ? 'success' : 'neutral'">{{ $attempt->status }}</x-admin.badge><span class="admin-ui-meta">{{ $attempt->paid_at ?? '—' }}</span></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">没有支付尝试。</td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$attempts" />
    </x-admin.card>
    <x-admin.card title="支付回调核查" description="核对网关交易与已记录的处理结果。">
        <x-admin.table label="支付回调核查">
            <x-slot:head><tr><th scope="col">ID / 网关交易</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">处理结果 / 时间</th></tr></x-slot:head>
            @forelse($callbacks as $callback)<tr><td>#{{ $callback->id }} {{ $callback->gateway }}<span class="admin-ui-meta">{{ $callback->gateway_trade_no }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $callback->amount }} {{ $invoice->currency }}</td><td><x-admin.badge :tone="$callback->outcome->requiresAlert() ? 'warning' : ($callback->outcome->isAccepted() ? 'success' : 'neutral')">{{ $callback->outcome->value }}</x-admin.badge><span class="admin-ui-meta">{{ $callback->processed_at ?? '—' }}</span></td></tr>
            @empty<tr><td colspan="3" class="admin-ui-empty">没有支付回调。</td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$callbacks" />
    </x-admin.card>
    @if($recoveryInfo)
        <x-admin.card title="续费恢复核对（只读）">
            <div class="admin-ui-card-body">
                @foreach($recoveryInfo['invoices'] as $member)<p><a class="admin-ui-link" href="/admin/finance/invoice?invoice_id={{ $member->id }}">{{ $member->invoice_no }}</a> · {{ $member->status->value }}</p>@endforeach
                <p>当前单：{{ $recoveryInfo['current']->invoice_no }}。{{ $recoveryInfo['eligible'] ? '可由客户本人重新核价确认；运营不可代扣钱包。' : $recoveryInfo['reason'] }}</p>
            </div>
        </x-admin.card>
    @endif
</div>
</x-filament-panels::page>
