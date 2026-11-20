<div class="admin-workspace-columns">
    <div class="admin-workspace-main">
        <x-admin.card id="invoice-summary" title="原支付计划">
            <div class="admin-ui-card-body">
                <p class="admin-invoice-plan">账单总额 {{ $invoice->total_amount }} = 钱包 {{ $invoice->credit_applied }} + 网关 {{ $invoice->gateway_amount }}</p>
                <p class="admin-ui-meta">{{ $invoice->currency }} · 优惠 {{ $invoice->discount_amount }}（已计入总额）</p>
                <dl class="admin-ui-facts">
                    <div><dt>客户</dt><dd>@can('customers.view')<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('customer', $invoice->user_id, $workspaceOrigin('invoice-summary')) }}">客户 #{{ $invoice->user_id }}</a>@else 客户 #{{ $invoice->user_id }}@endcan</dd></div>
                    <div><dt>商品</dt><dd>{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值' : '商品 #'.($invoice->product_id ?? '—') }}</dd></div>
                    <div><dt>关联服务</dt><dd>@if($instance)@can('upstream.view')<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, $workspaceOrigin('invoice-summary')) }}">#{{ $instance->id }} {{ $instance->display_name }}</a>@else #{{ $instance->id }} {{ $instance->display_name }}@endcan @else 无关联服务@endif</dd></div>
                    <div><dt>创建时间</dt><dd>{{ $invoice->created_at ?? '—' }}</dd></div>
                    <div><dt>支付时间</dt><dd>{{ $invoice->paid_at ?? '—' }}</dd></div>
                    <div><dt>账单到期</dt><dd>{{ $invoice->expires_at ?? '—' }}</dd></div>
                    <div><dt>计划网关</dt><dd>{{ $invoice->gateway ?? '—' }}</dd></div>
                    <div><dt>预计上游成本</dt><dd>{{ $invoice->upstream_expected_cost ?? '—' }}</dd></div>
                </dl>
                <details class="admin-invoice-help"><summary>金额口径</summary><p>原支付计划保留建单时的钱包／网关分配；优惠已计入总额，不再减一次。线下收款不会改写原计划。</p></details>
            </div>
        </x-admin.card>
    </div>
    <aside class="admin-workspace-rail" aria-label="账单收款与操作">
        <x-admin.card title="实际收款来源">
            <div class="admin-ui-card-body">
                @foreach($receiptFacts as $fact)
                    <div class="admin-invoice-receipt"><span>{{ $fact['label'] }}</span><strong>{{ $fact['amount'] }} {{ $invoice->currency }}</strong><span class="admin-ui-meta">流水 #{{ $fact['id'] }} · {{ $fact['created_at'] }}</span></div>
                @endforeach
                @if($offlineReceipt)
                    <p><x-admin.badge tone="success">线下收款已登记</x-admin.badge></p>
                    <dl class="admin-ui-facts">
                        <div><dt>渠道 / 金额</dt><dd>{{ $offlineReceipt['external_channel'] === 'bank_transfer' ? '银行转账' : '现金' }} · {{ $offlineReceipt['amount'] }} {{ $offlineReceipt['currency'] }}</dd></div>
                        <div><dt>凭证编号</dt><dd>{{ $offlineReceipt['masked_reference'] }}</dd></div>
                        <div><dt>实际收款时间</dt><dd>{{ $offlineReceipt['received_at'] }}</dd></div>
                        <div><dt>登记人 / 流水</dt><dd>管理员 #{{ $offlineReceipt['processed_by'] }} · #{{ $offlineReceipt['payment_transaction_id'] }}</dd></div>
                    </dl>
                    @if($canRecordOffline)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="offline-receipt-details" x-on:click="document.getElementById('offline-receipt-details').showModal(); $dispatch('offline-receipt-read')">查看完整凭证（记录审计）</button>@endif
                @elseif($receiptFacts === [])
                    <p class="admin-invoice-unrecorded">未记录实际收款凭证</p>
                @endif
                <a class="admin-ui-link" href="{{ $tabLinks['payments']['url'] }}">核对支付尝试与回调 →</a>
            </div>
        </x-admin.card>
        <x-admin.card title="账单操作">
            <div class="admin-ui-card-body admin-invoice-actions">
                @if($offlineAllowed)
                    <button type="button" class="admin-ui-button admin-ui-button-primary" aria-haspopup="dialog" aria-controls="invoice-offline-receipt" x-on:click="document.getElementById('invoice-offline-receipt').showModal()">登记已收到的线下款</button>
                @elseif($canRecordOffline && $invoice->status === \App\Enums\InvoiceStatus::UNPAID && $offlineReceipt === null && $offlineReason)
                    <p class="admin-ui-meta">{{ $offlineReason }}</p>
                @endif
                @if($invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP)
                    <p>充值账单仅核查到账，暂不支持充值退款。</p>
                @elseif($canRefund)
                    <button type="button" class="admin-ui-button admin-ui-button-danger" aria-haspopup="dialog" aria-controls="invoice-refund" x-on:click="document.getElementById('invoice-refund').showModal()">全额退款 {{ $invoice->total_amount }} {{ $invoice->currency }}</button>
                @elseif(! $offlineAllowed)
                    <p class="admin-ui-meta">当前没有可执行的收退款操作。</p>
                @endif
            </div>
        </x-admin.card>
    </aside>
</div>
