<x-filament-panels::page>
@include('filament.inventory-feedback')
<p>登记已经核实收到的外部 CNY 款项，一笔收款凭证只对应一笔应收。钱包、可用佣金与冻结佣金保持原金额。</p>
@if($correction)<p class="inventory-box">正在更正清偿 #{{ $correction->id }}（原用户 #{{ $correction->user_id }}，原应收 #{{ $correction->clawback_transaction_id }}）。原清偿已完整冲正，请选择正确应收后登记实际收款。<a class="inventory-link" href="?clawback={{ $correction->clawback_transaction_id }}">取消更正</a></p>@endif
<form method="get" action="/admin/finance/receivables" class="inventory-form"><label>用户 ID<input type="number" min="1" name="user_id" value="{{ request('user_id') }}"></label><button class="inventory-button">筛选</button></form>
<section class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>原流水 / 用户 / 账单</th><th>本金 / 清偿 / 冲正 / 剩余</th><th>核查</th></tr></thead><tbody>
@forelse($roots as $root)
@php($row = $projections->get($root->id))
<tr><td>#{{ $root->id }} / 用户 #{{ $root->user_id }} / 账单 #{{ $root->invoice_id }}</td><td>@if($row === null)待完整核对@else{{ $row['principal'] }} / {{ $row['settled'] }} / {{ $row['reversed'] }} / {{ $row['remaining'] }} CNY @if(!$row['valid'])<p role="alert">{{ $row['error'] }}</p>@endif @endif</td><td><a class="inventory-link" href="?clawback={{ $root->id }}@if($correction)&amp;corrects_settlement={{ $correction->id }}@endif">{{ $correction ? '选择此笔应收' : '查看清偿记录' }}</a></td></tr>
@empty<tr><td colspan="3">暂无退款佣金应收记录。</td></tr>@endforelse
</tbody></table>{{ $roots->links() }}</section>
@if($selected)
<section class="inventory-box"><h2>应收 #{{ $selected->id }} · 用户 #{{ $selected->user_id }}</h2>@if($projection)<p>本金 {{ $projection['principal'] }}，当前剩余 {{ $projection['remaining'] }} CNY。</p>@endif
@if($projection === null)<p role="alert">本笔证据待完整核对，请完成后继续原请求。</p>
@if(isset($receivableCheck['receivable_check_url'][0]))<a href="{{ $receivableCheck['receivable_check_url'][0] }}">查看完整核验进度</a>@endif
@elseif(!$projection['valid'])<p role="alert" class="inventory-alert">{{ $projection['error'] }}</p>
@elseif($projection['remaining'] !== '0.00')
@can('finance.receivable')
@php($channelOptions = ['bank' => '银行转账', 'alipay' => '支付宝', 'wechat' => '微信支付'])
@php($selectedChannel = is_string(old('external_channel')) ? old('external_channel') : 'bank')
<form method="post" action="/admin/finance/receivables/{{ $selected->id }}/settlements" class="inventory-form" x-data="{ customChannel: {{ !array_key_exists($selectedChannel, $channelOptions) ? 'true' : 'false' }} }">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><input type="hidden" name="version" value="{{ $projection['version'] }}"><input type="hidden" name="source" value="external_received">
<label>本次已收金额 CNY<input name="amount" value="{{ old('amount', $correction?->amount) }}" required pattern="[0-9]{1,10}\.[0-9]{2}" placeholder="0.00"></label>
@if($correction)
<input type="hidden" name="corrects_settlement_id" value="{{ $correction->id }}"><input type="hidden" name="external_channel" value="{{ $correction->external_channel }}"><input type="hidden" name="external_reference" value="{{ $correction->external_reference }}">
<p>沿用原收款渠道：{{ $channelOptions[$correction->external_channel] ?? $correction->external_channel }}；凭证尾号：{{ substr($correction->external_reference, -4) }}。</p>
@else
<label>收款渠道<select name="external_channel" @change="customChannel = $event.target.value === ''">@foreach($channelOptions as $channel => $label)<option value="{{ $channel }}" @selected($selectedChannel === $channel)>{{ $label }}</option>@endforeach<option value="" @selected(!array_key_exists($selectedChannel, $channelOptions))>其他渠道</option></select></label>
<label x-show="customChannel">其他渠道标识<input name="custom_external_channel" value="{{ old('custom_external_channel', array_key_exists($selectedChannel, $channelOptions) ? '' : $selectedChannel) }}" :required="customChannel" :disabled="!customChannel" pattern="[a-z][a-z0-9_\-]{0,31}" maxlength="32" placeholder="例如 paypal"></label>
<label>收款凭证号<input name="external_reference" value="{{ old('external_reference') }}" required maxlength="128" autocomplete="off"></label>
@endif
<label>实际收款时间<input name="received_at" type="datetime-local" required value="{{ old('received_at', ($correction?->received_at ?? now())->format('Y-m-d\TH:i')) }}"></label>
<label>备注（选填）<input name="reason" value="{{ old('reason') }}" maxlength="255"></label>
<label><input type="checkbox" name="confirm" value="1" required>已经核实实际收到上述 CNY 款项，凭证未分配给其他有效清偿</label><button class="inventory-button">登记已收款</button>
</form>
@endcan
@endif</section>
<section class="inventory-box inventory-scroll"><h2>清偿与冲正历史</h2><table class="inventory-table"><thead><tr><th>清偿 / 金额 / 时间</th><th>凭证 / 状态</th><th>更正</th></tr></thead><tbody>
@forelse($settlements as $entry)<tr><td>#{{ $entry->id }} · {{ $entry->amount }} CNY<br>{{ $entry->received_at }}</td><td>{{ $entry->external_channel }} · 尾号 {{ substr($entry->external_reference, -4) }}<br>{{ $entry->reversal ? '已完整冲正 #'.$entry->reversal->id : '有效清偿' }}</td><td>
@if($entry->reversal)
@can('finance.receivable')<a class="inventory-link" href="?clawback={{ $selected->id }}&amp;corrects_settlement={{ $entry->id }}">更正这笔收款</a>@endcan
@elseif($projection !== null && $projection['valid'])
@can('finance.receivable')
<form method="post" action="/admin/finance/receivable-settlements/{{ $entry->id }}/reverse" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ (int) old('settlement_id') === $entry->id ? old('request_id') : (string) \Illuminate\Support\Str::ulid() }}"><input type="hidden" name="settlement_id" value="{{ $entry->id }}"><input type="hidden" name="version" value="{{ $projection['version'] }}">
<label>冲正证据引用<input name="correction_reference" value="{{ (int) old('settlement_id') === $entry->id ? old('correction_reference') : '' }}" required maxlength="128"></label><label>原因<input name="reason" value="{{ old('reason') }}" required maxlength="255"></label>
<label><input name="confirm" type="checkbox" value="1" required>核实原清偿不成立或外部退回已完成，完整恢复此笔应收</label><button class="inventory-button">完整冲正 {{ $entry->amount }} CNY</button></form>
@endcan
@endif</td></tr>@empty<tr><td colspan="3">尚无清偿登记。</td></tr>@endforelse
</tbody></table>{{ $settlements->links() }}<p>冲正只更正应收登记，不发起退款；部分金额填错时完整冲正错误登记，再以新请求按正确金额登记并引用原编号。</p></section>
@can('finance.receivable-review')
<section class="inventory-box"><h2>完整证据人工复核</h2><p>当前失效标志：{{ $selectedUser->receivable_reconciliation_required ? '待复核' : '无' }}。只有完整证据通过后可以解除。</p>
<form method="post" action="/admin/finance/receivables/{{ $selectedUser->id }}/review" class="inventory-form">@csrf
<input type="hidden" name="request_id" value="{{ $requestId }}"><label>复核原因<input name="reason" required maxlength="255" value="{{ old('reason') }}"></label>
<label><input type="checkbox" name="confirm" value="1" required>核对原身份全部应收证据并授权解除失效标志</label><button class="inventory-button">完整复核</button></form></section>
@endcan
@endif
</x-filament-panels::page>
