<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-finance-invoice.css?v=20261120-c3">
@php
    $workspaceQuery = \App\Support\AdminWorkspaceNavigation::query(auth()->user(), 'invoice', request()->query());
    $workspaceOrigin = fn (?string $section = null): array => \App\Support\AdminWorkspaceNavigation::origin(auth()->user(), 'invoice', $invoice->id, $workspaceQuery, $section);
    $workspaceAction = fn (string $path): string => $path.($workspaceQuery === [] ? '' : '?'.http_build_query($workspaceQuery));
    $workspaceReturn = \App\Support\AdminWorkspaceNavigation::returnLink(auth()->user(), $workspaceQuery, $returnUrl, '返回账单');
    $workspaceStatus = $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : $statusLabels[$invoice->status->value];
    $workspaceTone = match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' };
    $restoreOffline = $offlineAllowed && $errors->any() && old('_admin_form') === 'offline-receipt'
        && is_scalar(old('invoice_id')) && (string) old('invoice_id') === (string) $invoice->id;
    $offlineValue = function ($key, $default = '') use ($restoreOffline) {
        $value = $restoreOffline ? old($key, $default) : $default;
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $offlineRequest = $offlineValue('request_id');
    $offlineRequest = is_string($offlineRequest) && \Illuminate\Support\Str::isUlid($offlineRequest) ? $offlineRequest : (string) \Illuminate\Support\Str::ulid();
    $taskLabels = ['pending' => '待执行', 'running' => '执行中', 'success' => '已完成', 'failed' => '失败', 'stale' => '已失效'];
@endphp
<div class="admin-ui admin-finance-invoice" x-data>
    @include('filament.inventory-feedback')
    @if(session('status'))<p role="status" class="inventory-alert">{{ session('status') }}</p>@endif
    <x-admin.workspace-header :name="$invoice->invoice_no" :identity="'账单 #'.$invoice->id.' · '.$typeLabels[$invoice->type->value]" :status="$workspaceStatus" :tone="$workspaceTone" description="核对原支付计划、实际收款与履约记录，金额以原账单为准。" :return-url="$workspaceReturn['url']" :return-label="$workspaceReturn['label']">
        <a class="admin-ui-button" href="/admin/finance/ledger?invoice_id={{ $invoice->id }}">查看关联流水和回调</a>@if($invoice->type !== \App\Enums\InvoiceType::WALLET_TOPUP)@can('tasks.view')<a class="admin-ui-button" href="/admin/tasks?invoice_id={{ $invoice->id }}">关联任务</a>@endcan @endif
    </x-admin.workspace-header>
    <x-admin.card id="invoice-summary" title="账单信息">
        <div class="admin-ui-card-body">
            <dl class="admin-ui-facts">
                <div><dt>账单 / 客户</dt><dd>#{{ $invoice->id }} · @can('customers.view')<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('customer', $invoice->user_id, $workspaceOrigin('invoice-summary')) }}">客户 #{{ $invoice->user_id }}</a>@else 客户 #{{ $invoice->user_id }}@endcan</dd></div>
                <div><dt>类型 / 状态</dt><dd>{{ $typeLabels[$invoice->type->value] }} <x-admin.badge :tone="match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' }">{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : $statusLabels[$invoice->status->value] }}</x-admin.badge><span class="admin-ui-meta">{{ $invoice->type->value }} · {{ $invoice->status->value }}</span></dd></div>
                <div><dt>商品 / 币种</dt><dd>{{ $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP ? '钱包充值' : '商品 #'.($invoice->product_id ?? '—') }} · {{ $invoice->currency }}</dd></div>
                <div><dt>关联服务</dt><dd>@if($instance)@can('upstream.view')<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $instance->id, $workspaceOrigin('invoice-summary')) }}">#{{ $instance->id }} {{ $instance->display_name }}</a>@else #{{ $instance->id }} {{ $instance->display_name }}@endcan · {{ $instance->status->value }}@else 无关联服务@endif</dd></div>
                <div><dt>支付时间</dt><dd>{{ $invoice->paid_at ?? '—' }}</dd></div>
                <div><dt>账单到期</dt><dd>{{ $invoice->expires_at ?? '—' }}</dd></div>
                <div><dt>创建时间</dt><dd>{{ $invoice->created_at ?? '—' }}</dd></div>
                <div><dt>支付网关</dt><dd>{{ $invoice->gateway ?? '—' }}</dd></div>
            </dl>
        </div>
    </x-admin.card>
    <x-admin.card title="原支付计划" description="保留建单时金额与钱包／网关分配；线下收款不会改写原计划。">
        <div class="admin-ui-card-body">
            <p>账单总额 {{ $invoice->total_amount }} = 钱包 {{ $invoice->credit_applied }} + 网关 {{ $invoice->gateway_amount }}</p>
            <p class="inventory-note">优惠 {{ $invoice->discount_amount }}（已计入总额，不再减一次） · 预计上游成本 {{ $invoice->upstream_expected_cost ?? '—' }} · {{ $invoice->currency }}</p>
        </div>
    </x-admin.card>
    <x-admin.card title="账单明细" description="仅展示原账单允许公开的商品与开通费项目，不重新计价。">
        <x-admin.table label="账单明细"><x-slot:head><tr><th scope="col">项目</th><th scope="col" class="admin-ui-number">数量</th><th scope="col" class="admin-ui-number">单价</th><th scope="col" class="admin-ui-number">金额</th></tr></x-slot:head>
            @forelse($invoiceItems as $item)<tr><td>{{ $item['label'] }}</td><td class="admin-ui-number">{{ $item['qty'] }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $item['unit_price'] }} {{ $invoice->currency }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $item['amount'] }} {{ $invoice->currency }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">该历史账单未保存可展示的明细，金额以原账单记录为准。</td></tr>@endforelse
        </x-admin.table>
    </x-admin.card>
    <x-admin.card title="实际收款来源" description="实际线下收款凭证与原网关尝试、回调分别保存。">
        <div class="admin-ui-card-body">
            @if($offlineReceipt)
                <dl class="admin-ui-facts">
                    <div><dt>线下记录</dt><dd>#{{ $offlineReceipt['id'] }} <x-admin.badge tone="success">已登记</x-admin.badge></dd></div>
                    <div><dt>渠道 / 金额</dt><dd>{{ $offlineReceipt['external_channel'] === 'bank_transfer' ? '银行转账' : '现金' }} · {{ $offlineReceipt['amount'] }} {{ $offlineReceipt['currency'] }}</dd></div>
                    <div><dt>凭证编号</dt><dd>{{ $offlineReceipt['masked_reference'] }}<span class="admin-ui-meta">仅显示末四位</span></dd></div>
                    <div><dt>实际收款时间</dt><dd>{{ $offlineReceipt['received_at'] }}</dd></div>
                    <div><dt>登记人</dt><dd>管理员 #{{ $offlineReceipt['processed_by'] }}</dd></div>
                    <div><dt>关联付款流水</dt><dd>#{{ $offlineReceipt['payment_transaction_id'] }}</dd></div>
                </dl>
                @if($canRecordOffline)<button type="button" class="admin-ui-button" aria-haspopup="dialog" aria-controls="offline-receipt-details" x-on:click="document.getElementById('offline-receipt-details').showModal(); $dispatch('offline-receipt-read')">查看完整凭证（记录审计）</button>@endif
            @else
                <p>未登记线下收款；网关支付是否入账请核对下方尝试、回调及资金流水。</p>
                @if($offlineAllowed)<button type="button" class="admin-ui-button admin-ui-button-primary" aria-haspopup="dialog" aria-controls="invoice-offline-receipt" x-on:click="document.getElementById('invoice-offline-receipt').showModal()">登记已收到的线下款</button>
                @elseif($canRecordOffline)<p class="admin-ui-meta">{{ $offlineReason }}</p>@endif
            @endif
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
                        <form method="post" action="{{ $workspaceAction('/admin/finance/invoices/'.$invoice->id.'/refund') }}" class="inventory-form" x-data="{ mode: '{{ $refundInput && old('mode') === 'external_record' ? 'external_record' : 'refund_wallet' }}', keepService: {{ ($instance === null || !$instance->status->isTerminal()) && $refundInput && old('keep_service') === '1' ? 'true' : 'false' }} }">@csrf
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
    <x-admin.card title="关联资金流水" description="仅展示本账单的原始资金记录，流水不会因查看而改变。">
        <x-admin.table label="账单关联资金流水"><x-slot:head><tr><th scope="col">流水 / 类型</th><th scope="col" class="admin-ui-number">金额</th><th scope="col" class="admin-ui-number">变动后可用 / 冻结</th><th scope="col">引用 / 时间</th></tr></x-slot:head>
            @forelse($ledger as $entry)<tr><td>#{{ $entry->id }}<span class="admin-ui-meta">{{ match($entry->type->value) { 'wallet_adjust_credit' => '钱包加款', 'wallet_adjust_debit' => '钱包扣款', default => $entry->type->value } }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} {{ $invoice->currency }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td><td>{{ $entry->reference_key }}<span class="admin-ui-meta">{{ $entry->created_at }}</span></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">没有关联资金流水。</td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$ledger" />
    </x-admin.card>
    @if($tasks !== null)
    <x-admin.card id="invoice-tasks" title="关联履约任务" description="只读当前任务事实；重试仍进入原任务工作台核对。">
        <x-admin.table label="账单关联履约任务"><x-slot:head><tr><th scope="col">任务 / 服务</th><th scope="col">动作 / 状态</th><th scope="col">认领次数</th><th scope="col">执行时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($tasks as $task)<tr><td>#{{ $task->id }}<span class="admin-ui-meta">@if($task->instance_id !== null && auth()->user()->can('upstream.view'))<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $task->instance_id, $workspaceOrigin('invoice-tasks')) }}">服务 #{{ $task->instance_id }}</a>@else {{ $task->instance_id === null ? '未关联服务' : '服务 #'.$task->instance_id }}@endif</span></td><td>{{ $task->action }} <x-admin.badge :tone="$task->status->value === 'failed' ? 'danger' : ($task->status->value === 'success' ? 'success' : 'neutral')">{{ $taskLabels[$task->status->value] }}</x-admin.badge></td><td>{{ $task->attempts }} / {{ $task->max_attempts }}</td><td>{{ $task->executed_at ?? $task->available_at ?? '—' }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/tasks?task_id={{ $task->id }}">任务详情</a></td></tr>
            @empty<tr><td colspan="5" class="admin-ui-empty">没有关联履约任务。</td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tasks" />
    </x-admin.card>
    @endif
    @if($recoveryInfo)
        <x-admin.card id="invoice-recovery" title="续费恢复核对（只读）">
            <div class="admin-ui-card-body">
                @foreach($recoveryInfo['invoices'] as $member)<p><a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('invoice', $member->id, $workspaceOrigin('invoice-recovery')) }}">{{ $member->invoice_no }}</a> · {{ $member->status->value }}</p>@endforeach
                <p>当前单：{{ $recoveryInfo['current']->invoice_no }}。{{ $recoveryInfo['eligible'] ? '可由客户本人重新核价确认；运营不可代扣钱包。' : $recoveryInfo['reason'] }}</p>
            </div>
        </x-admin.card>
    @endif
    @if($offlineAllowed)
    <x-admin.dialog id="invoice-offline-receipt" title="登记已收到的线下款" description="仅登记已实际收到的银行转账或现金，准确结清原网关应付额；此操作不向银行或支付网关发起收款。" :show-errors="$restoreOffline" x-init="{{ $restoreOffline ? '$nextTick(() => $el.showModal())' : '' }}">
        <form method="post" action="{{ $workspaceAction('/admin/finance/invoices/'.$invoice->id.'/offline-receipt') }}" class="admin-finance-form">@csrf
            <input type="hidden" name="_admin_form" value="offline-receipt"><input type="hidden" name="invoice_id" value="{{ $invoice->id }}"><input type="hidden" name="request_id" value="{{ $offlineRequest }}"><input type="hidden" name="currency" value="CNY">
            <p class="admin-finance-wide">账单 {{ $invoice->invoice_no }} · 客户 #{{ $invoice->user_id }}</p>
            <label>本次实收金额（CNY）<input name="amount" value="{{ $invoice->gateway_amount }}" readonly aria-readonly="true"><span class="admin-ui-meta">固定为原网关应付额，提交时重新核对。</span></label>
            <label>实际收款渠道<select name="external_channel" required><option value="bank_transfer" @selected($offlineValue('external_channel') !== 'cash')>银行转账</option><option value="cash" @selected($offlineValue('external_channel') === 'cash')>现金</option></select></label>
            <label>收款凭证编号<input name="external_reference" required maxlength="128" value="" autocomplete="off"></label>
            <label>实际收款时间<input type="datetime-local" name="received_at" required max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ $offlineValue('received_at') }}"></label>
            <label class="admin-finance-wide">凭证说明（可选，加密保存）<textarea name="evidence" maxlength="2000" rows="3" autocomplete="off"></textarea><span class="admin-ui-meta">为保护凭证，验证失败后请重新填写凭证编号与说明。</span></label>
            <label class="admin-finance-wide">备注（可选）<input name="reason" maxlength="255" value="{{ $offlineValue('reason') }}"></label>
            <label class="admin-finance-wide"><span><input type="checkbox" name="confirm" value="1" required> 已核对实际到账、客户、金额与凭证，确认登记这笔线下收款。</span></label>
            <div class="admin-finance-form-actions admin-finance-wide"><button class="admin-ui-button admin-ui-button-primary">确认登记收款</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
        </form>
    </x-admin.dialog>
    @endif
    @if($offlineReceipt && $canRecordOffline)
    <x-admin.dialog id="offline-receipt-details" title="完整线下收款凭证" description="本次查看记录实际管理员审计；关闭后清除页面中的完整凭证。" x-data="{ loading: false, reference: '', evidence: '', message: '' }" x-on:close="reference = ''; evidence = ''; message = ''" x-on:offline-receipt-read.window="loading = true; reference = ''; evidence = ''; message = ''; fetch($el.querySelector('form').action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData($el.querySelector('form')) }).then(async response => { if (!response.ok) throw new Error('凭证读取失败，请核对权限后重试。'); return response.json(); }).then(data => { if ($el.open) { reference = data.external_reference; evidence = data.evidence || '未填写凭证说明'; } }).catch(error => { if ($el.open) message = error.message; }).finally(() => { loading = false; })">
        <form method="post" action="/admin/finance/offline-receipts/{{ $offlineReceipt['id'] }}/details">@csrf</form>
        <p x-show="loading" role="status">正在读取凭证…</p><p x-show="message" x-text="message" role="alert"></p>
        <dl class="admin-ui-facts" x-show="!loading && !message"><div><dt>完整凭证编号</dt><dd x-text="reference"></dd></div><div><dt>凭证说明</dt><dd class="admin-finance-evidence" x-text="evidence"></dd></div></dl>
    </x-admin.dialog>
    @endif
</div>
</x-filament-panels::page>
