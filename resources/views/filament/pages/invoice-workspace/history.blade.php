@if($tab === 'items')
    <x-admin.card id="invoice-items" title="账单明细">
        <x-admin.table label="账单明细"><x-slot:head><tr><th scope="col">项目</th><th scope="col" class="admin-ui-number">数量</th><th scope="col" class="admin-ui-number">单价</th><th scope="col" class="admin-ui-number">金额</th></tr></x-slot:head>
            @forelse($invoiceItems as $item)<tr><td>{{ $item['label'] }}</td><td class="admin-ui-number">{{ $item['qty'] }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $item['unit_price'] }} {{ $invoice->currency }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $item['amount'] }} {{ $invoice->currency }}</td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">该历史账单未保存可展示的明细，金额以原账单记录为准。</td></tr>@endforelse
        </x-admin.table>
    </x-admin.card>
@elseif($tab === 'payments')
    <x-admin.card id="invoice-payments" title="支付尝试">
        <x-admin.table label="支付尝试">
            <x-slot:head><tr><th scope="col">ID / 网关</th><th scope="col">精确订单号</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">状态 / 支付时间</th></tr></x-slot:head>
            @forelse($attempts as $attempt)<tr><td>#{{ $attempt->id }} <span class="admin-ui-meta">{{ $attempt->gateway }}</span></td><td>{{ $attempt->out_trade_no }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $attempt->amount }} {{ $invoice->currency }}</td><td><x-admin.badge :tone="$attempt->status === 'paid' ? 'success' : 'neutral'">{{ $attempt->status }}</x-admin.badge><span class="admin-ui-meta">{{ $attempt->paid_at ?? '—' }}</span></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">没有支付尝试。</td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$attempts" />
    </x-admin.card>
    <x-admin.card title="支付回调核查">
        <x-admin.table label="支付回调核查">
            <x-slot:head><tr><th scope="col">ID / 网关交易</th><th scope="col" class="admin-ui-number">金额</th><th scope="col">处理结果 / 时间</th></tr></x-slot:head>
            @forelse($callbacks as $callback)<tr><td>#{{ $callback->id }} {{ $callback->gateway }}<span class="admin-ui-meta">{{ $callback->gateway_trade_no }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $callback->amount }} {{ $invoice->currency }}</td><td><x-admin.badge :tone="$callback->outcome->requiresAlert() ? 'warning' : ($callback->outcome->isAccepted() ? 'success' : 'neutral')">{{ $callback->outcome->value }}</x-admin.badge><span class="admin-ui-meta">{{ $callback->processed_at ?? '—' }}</span></td></tr>
            @empty<tr><td colspan="3" class="admin-ui-empty">没有支付回调。</td></tr>@endforelse
        </x-admin.table>
        <x-admin.pagination :paginator="$callbacks" />
    </x-admin.card>
@elseif($tab === 'ledger')
    <x-admin.card id="invoice-ledger" title="关联资金流水">
        <x-admin.table label="账单关联资金流水"><x-slot:head><tr><th scope="col">流水 / 类型</th><th scope="col" class="admin-ui-number">金额</th><th scope="col" class="admin-ui-number">变动后可用 / 冻结</th><th scope="col">引用 / 时间</th></tr></x-slot:head>
            @forelse($ledger as $entry)<tr><td>#{{ $entry->id }}<span class="admin-ui-meta">{{ match($entry->type->value) { 'wallet_adjust_credit' => '钱包加款', 'wallet_adjust_debit' => '钱包扣款', default => $entry->type->value } }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} {{ $invoice->currency }}</td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->balance_after ?? '—' }} / {{ $entry->frozen_after ?? '—' }}</td><td>{{ $entry->reference_key }}<span class="admin-ui-meta">{{ $entry->created_at }}</span></td></tr>
            @empty<tr><td colspan="4" class="admin-ui-empty">没有关联资金流水。</td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$ledger" />
    </x-admin.card>
@elseif($tab === 'tasks')
    @if($tasks !== null)
    <x-admin.card id="invoice-tasks" title="关联履约任务">
        <x-admin.table label="账单关联履约任务"><x-slot:head><tr><th scope="col">任务 / 服务</th><th scope="col">动作 / 状态</th><th scope="col">认领次数</th><th scope="col">执行时间</th><th scope="col" class="admin-ui-row-actions">操作</th></tr></x-slot:head>
            @forelse($tasks as $task)<tr><td>#{{ $task->id }}<span class="admin-ui-meta">@if($task->instance_id !== null && auth()->user()->can('upstream.view'))<a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('instance', $task->instance_id, $workspaceOrigin('invoice-tasks')) }}">服务 #{{ $task->instance_id }}</a>@else {{ $task->instance_id === null ? '未关联服务' : '服务 #'.$task->instance_id }}@endif</span></td><td>{{ $task->action }} <x-admin.badge :tone="$task->status->value === 'failed' ? 'danger' : ($task->status->value === 'success' ? 'success' : 'neutral')">{{ $taskLabels[$task->status->value] }}</x-admin.badge></td><td>{{ $task->attempts }} / {{ $task->max_attempts }}</td><td>{{ $task->executed_at ?? $task->available_at ?? '—' }}</td><td class="admin-ui-row-actions"><a class="admin-ui-link" href="/admin/tasks?task_id={{ $task->id }}">任务详情</a></td></tr>
            @empty<tr><td colspan="5" class="admin-ui-empty">没有关联履约任务。</td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$tasks" />
    </x-admin.card>
    @endif
@elseif($tab === 'recovery')
    @if($recoveryInfo)
        <x-admin.card id="invoice-recovery" title="续费恢复核对（只读）">
            <div class="admin-ui-card-body">
                @foreach($recoveryInfo['invoices'] as $member)<p><a class="admin-ui-link" href="{{ \App\Support\AdminWorkspaceNavigation::url('invoice', $member->id, $workspaceOrigin('invoice-recovery')) }}">{{ $member->invoice_no }}</a> · {{ $member->status->value }}</p>@endforeach
                <p>当前单：{{ $recoveryInfo['current']->invoice_no }}。{{ $recoveryInfo['eligible'] ? '可由客户本人重新核价确认；运营不可代扣钱包。' : $recoveryInfo['reason'] }}</p>
            </div>
        </x-admin.card>
    @endif
@endif
