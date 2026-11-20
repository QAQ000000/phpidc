<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="{{ asset('css/admin-operations.css') }}">
<div class="admin-ui admin-operations-page" x-data>
@include('filament.inventory-feedback')
<header class="admin-ui-heading"><div><p>登记已经核实收到的外部 CNY 款项，一笔收款凭证只对应一笔应收。钱包、可用佣金与冻结佣金保持原金额。</p></div></header>
@if($correction)<div role="status" class="inventory-alert">正在更正清偿 #{{ $correction->id }}（原用户 #{{ $correction->user_id }}，原应收 #{{ $correction->clawback_transaction_id }}）。原清偿已完整冲正，请选择正确应收后登记实际收款。<a class="admin-ui-link" href="?clawback={{ $correction->clawback_transaction_id }}">取消更正</a></div>@endif
<x-admin.card title="退款佣金应收">
    <x-admin.filters action="/admin/finance/receivables"><label>用户 ID<input type="number" min="1" name="user_id" value="{{ request('user_id') }}"></label></x-admin.filters>
    <x-admin.table label="退款佣金应收"><x-slot:head><tr><th scope="col">原流水 / 用户 / 账单</th><th scope="col" class="admin-ui-number">本金 / 清偿 / 冲正 / 剩余</th><th scope="col" class="admin-ui-row-actions">核查</th></tr></x-slot:head>
        @forelse($roots as $root)@php $row = $projections->get($root->id); @endphp<tr>
            <td>流水 #{{ $root->id }}<span class="admin-ui-meta">用户 #{{ $root->user_id }} · 账单 #{{ $root->invoice_id }}</span></td>
            <td class="admin-ui-number">@if($row === null)<x-admin.badge tone="warning">待完整核对</x-admin.badge>@else<span class="admin-ui-nowrap">{{ $row['principal'] }} / {{ $row['settled'] }} / {{ $row['reversed'] }} / {{ $row['remaining'] }} CNY</span>@if(!$row['valid'])<p role="alert" class="admin-operations-note">{{ $row['error'] }}</p>@endif @endif</td>
            <td class="admin-ui-row-actions"><a class="admin-ui-link" href="?clawback={{ $root->id }}@if($correction)&amp;corrects_settlement={{ $correction->id }}@endif @if(request()->integer('user_id'))&amp;user_id={{ request()->integer('user_id') }}@endif">{{ $correction ? '选择此笔应收' : '查看清偿记录' }}</a></td>
        </tr>@empty<tr><td colspan="3" class="admin-ui-empty"><strong>暂无退款佣金应收记录。</strong></td></tr>@endforelse
    </x-admin.table><x-admin.pagination :paginator="$roots" />
</x-admin.card>
@if($selected)
    @php
        $restoreSettlement = $formError === 'receivable-settlement' && (int) old('clawback_id') === $selected->id && (int) old('corrects_settlement_id') === (int) ($correction?->id ?? 0);
        $restoreReview = $formError === 'receivable-review' && (int) old('clawback_id') === $selected->id;
        $canSettle = $projection !== null && $projection['valid'] && $projection['remaining'] !== '0.00';
    @endphp
    <x-admin.card :title="'应收 #'.$selected->id.' · 用户 #'.$selected->user_id">
        <x-slot:actions>@if($canSettle)@can('finance.receivable')<button type="button" class="admin-ui-button admin-ui-button-primary" x-on:click="document.getElementById('receivable-settlement').showModal()">登记已收款</button>@endcan @endif<a class="admin-ui-button" href="/admin/finance/receivables">返回应收列表</a></x-slot:actions>
        <div class="admin-ui-card-body">
            @if($projection)<dl class="admin-ui-facts"><div><dt>原始本金</dt><dd>{{ $projection['principal'] }} CNY</dd></div><div><dt>当前剩余</dt><dd>{{ $projection['remaining'] }} CNY</dd></div><div><dt>核验状态</dt><dd><x-admin.badge :tone="$projection['valid'] ? 'success' : 'danger'">{{ $projection['valid'] ? '证据有效' : '证据异常' }}</x-admin.badge></dd></div></dl>@endif
            @if($projection === null)<p role="alert">本笔证据待完整核对，请完成后继续原请求。</p>@if(isset($receivableCheck['receivable_check_url'][0]))<a class="admin-ui-link" href="{{ $receivableCheck['receivable_check_url'][0] }}">查看完整核验进度</a>@endif
            @elseif(!$projection['valid'])<p role="alert" class="inventory-alert">{{ $projection['error'] }}</p>@endif
        </div>
    </x-admin.card>
    <x-admin.card title="清偿与冲正历史" description="冲正只更正应收登记，不发起退款；部分金额填错时完整冲正错误登记，再以新请求按正确金额登记并引用原编号。">
        <x-admin.table label="清偿与冲正历史"><x-slot:head><tr><th scope="col">清偿 / 收款时间</th><th scope="col" class="admin-ui-number">金额 / 凭证</th><th scope="col">状态</th><th scope="col" class="admin-ui-row-actions">更正</th></tr></x-slot:head>
            @forelse($settlements as $entry)<tr>
                <td>清偿 #{{ $entry->id }}<span class="admin-ui-meta">{{ $entry->received_at }}</span></td><td class="admin-ui-number admin-ui-nowrap">{{ $entry->amount }} CNY<span class="admin-ui-meta">{{ $entry->external_channel }} · 尾号 {{ substr($entry->external_reference, -4) }}</span></td><td><x-admin.badge :tone="$entry->reversal ? 'neutral' : 'success'">{{ $entry->reversal ? '已完整冲正 #'.$entry->reversal->id : '有效清偿' }}</x-admin.badge></td>
                <td class="admin-ui-row-actions">@if($entry->reversal)@can('finance.receivable')<a class="admin-ui-link" href="?clawback={{ $selected->id }}&amp;corrects_settlement={{ $entry->id }}">更正这笔收款</a>@endcan @elseif($projection !== null && $projection['valid'])@can('finance.receivable')<button type="button" class="admin-ui-link" x-on:click="document.getElementById('receivable-reversal-{{ $entry->id }}').showModal()">完整冲正</button>@endcan @endif</td>
            </tr>@empty<tr><td colspan="4" class="admin-ui-empty"><strong>尚无清偿登记。</strong></td></tr>@endforelse
        </x-admin.table><x-admin.pagination :paginator="$settlements" />
    </x-admin.card>
    @can('finance.receivable-review')
        <x-admin.card title="完整证据人工复核"><x-slot:actions><button type="button" class="admin-ui-button" x-on:click="document.getElementById('receivable-review').showModal()">完整复核</button></x-slot:actions><div class="admin-ui-card-body"><p>当前失效标志：{{ $selectedUser->receivable_reconciliation_required ? '待复核' : '无' }}。只有完整证据通过后可以解除。</p></div></x-admin.card>
        <x-admin.dialog id="receivable-review" title="完整证据人工复核" :show-errors="$restoreReview" x-init="{{ $restoreReview ? '$nextTick(() => $el.showModal())' : '' }}">
            <form method="post" action="/admin/finance/receivables/{{ $selectedUser->id }}/review" class="inventory-form admin-operations-form">@csrf
                <input type="hidden" name="_admin_form" value="receivable-review"><input type="hidden" name="clawback_id" value="{{ $selected->id }}"><input type="hidden" name="request_id" value="{{ $restoreReview ? old('request_id') : $requestId }}">
                <label class="admin-operations-wide">复核原因<input name="reason" required maxlength="255" value="{{ $restoreReview ? old('reason') : '' }}"></label><label class="admin-operations-wide"><span><input type="checkbox" name="confirm" value="1" required @checked($restoreReview && old('confirm'))> 核对原身份全部应收证据并授权解除失效标志</span></label>
                <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">完整复核</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
            </form>
        </x-admin.dialog>
    @endcan
    @can('finance.receivable')
        @if($canSettle)@include('filament.settlements.receivable-form')@endif
        @if($projection !== null && $projection['valid'])
            @foreach($settlements as $entry)@if(!$entry->reversal)
                @php $restoreReversal = $formError === 'receivable-reversal' && (int) old('settlement_id') === $entry->id; @endphp
                <x-admin.dialog :id="'receivable-reversal-'.$entry->id" :title="'完整冲正清偿 #'.$entry->id" :show-errors="$restoreReversal" x-init="{{ $restoreReversal ? '$nextTick(() => $el.showModal())' : '' }}">
                    <p>完整冲正 {{ $entry->amount }} CNY；此操作只更正应收登记，不会向外部发起退款。</p>@if($restoreReversal)<p class="admin-operations-note">冲正证据引用不保留，请重新输入。</p>@endif
                    <form method="post" action="/admin/finance/receivable-settlements/{{ $entry->id }}/reverse" class="inventory-form admin-operations-form">@csrf
                        <input type="hidden" name="_admin_form" value="receivable-reversal"><input type="hidden" name="clawback_id" value="{{ $selected->id }}"><input type="hidden" name="request_id" value="{{ $restoreReversal ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="settlement_id" value="{{ $entry->id }}"><input type="hidden" name="version" value="{{ $projection['version'] }}">
                        <label class="admin-operations-wide">冲正证据引用<input name="correction_reference" required maxlength="128" autocomplete="off"></label><label class="admin-operations-wide">原因<input name="reason" value="{{ $restoreReversal ? old('reason') : '' }}" required maxlength="255"></label>
                        <label class="admin-operations-wide"><span><input name="confirm" type="checkbox" value="1" required @checked($restoreReversal && old('confirm'))> 核实原清偿不成立或外部退回已完成，完整恢复此笔应收</span></label>
                        <div class="admin-operations-form-actions"><button class="admin-ui-button admin-ui-button-primary">完整冲正 {{ $entry->amount }} CNY</button><button type="button" class="admin-ui-button" x-on:click="$el.closest('dialog').close()">取消</button></div>
                    </form>
                </x-admin.dialog>
            @endif @endforeach
        @endif
    @endcan
@endif
</div>
</x-filament-panels::page>
