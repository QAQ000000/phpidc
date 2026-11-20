@extends('sales.layout')
@section('title', ['coupons'=>'我的优惠券','affiliate'=>'推广与佣金','tickets'=>'我的工单','ticket'=>'工单详情','downloads'=>'下载中心'][$section])
@section('content')
@php
    $oldText = static function (string $key, string $form): string {
        $source = old('ui_form');
        $value = $source === null || $source === $form ? old($key) : '';
        return is_string($value) || is_int($value) ? (string) $value : '';
    };
@endphp
<x-console.page-header :title="['coupons'=>'我的优惠券','affiliate'=>'推广与佣金','tickets'=>'我的工单','ticket'=>'工单详情','downloads'=>'下载中心'][$section]">
    @if($section === 'ticket')
        <x-slot:actions><a class="console-button is-secondary" href="/console/tickets">返回工单列表</a></x-slot:actions>
    @endif
</x-console.page-header>
@if($section === 'coupons')
    <x-console.card title="领取优惠券" description="领取不占用额度；在新购或新建续费账单填写券码。首轮仅 CNY，取消和退款不恢复次数。">
        <form method="post" action="/console/coupons">@csrf<input type="hidden" name="ui_form" value="coupon-claim"><label>优惠券码<input name="code" value="{{ $oldText('code', 'coupon-claim') }}" required maxlength="64"></label><button class="console-button">领取优惠券</button></form>
    </x-console.card>
    <x-console.card title="已领优惠券">

            <div class="console-grid">
                @forelse($coupons as $coupon)
                    <div class="console-stat"><h3>{{ $coupon->code }}</h3><p>{{ $coupon->type === 'percent' ? '减免百分比' : '固定减免' }} {{ $coupon->value }}{{ $coupon->type === 'percent' ? '%' : ' CNY' }}</p><p class="console-muted">最低订单额 {{ $coupon->min_order_amount }} CNY · 每人 {{ $coupon->per_user_limit }} 次</p><p><x-console.badge :tone="$coupon->is_active ? 'success' : 'neutral'">{{ $coupon->is_active ? '启用' : '停用' }}</x-console.badge> · 有效至 {{ $coupon->valid_until ?? '不限' }}</p></div>
                @empty<p class="console-empty">尚未领取优惠券。</p>@endforelse
            </div>
            {{ $coupons->links('sales.pagination') }}

    </x-console.card>
    <x-console.card title="最近使用记录">
        @forelse($usages as $usage)<p><a href="/console/invoices/{{ $usage->invoice_id }}">账单 #{{ $usage->invoice_id }}</a> · 减免 {{ \App\Support\Money::of($usage->discount_amount) }} CNY</p>@empty<p class="console-empty">暂无使用记录。</p>@endforelse
    </x-console.card>
@elseif($section === 'affiliate')
    <x-console.card title="推广链接" description="仅新注册账户归因；已登录用户不会改变归属。"><a href="{{ $referralUrl }}">{{ url($referralUrl) }}</a><p class="console-muted">首轮仅单层 CNY 新购计佣，以折后账单总额为基础。默认冻结 7 天，最终以建单快照为准。可选择转入本站钱包或申请人工外部转账。</p></x-console.card>
    <x-console.card title="转入本站钱包">
        <x-slot:actions><a class="console-button is-secondary" href="/console/affiliate/external">申请外部提现 / 查看出款进度</a></x-slot:actions>

            <dl class="console-stats"><div class="console-stat"><dt>账面余额</dt><dd>{{ $balance }} CNY</dd></div><div class="console-stat"><dt>申请占用</dt><dd>{{ $pending }} CNY</dd></div><div class="console-stat"><dt>可申请</dt><dd>{{ $available }} CNY</dd></div></dl>
            @if($hasReceivable)<p class="error" role="alert">存在退款应收，暂停提现；请联系财务核对原流水，不能用新佣金自动抵销。</p>@else
                @php($withdrawalRequest = is_string($requestId) && \Illuminate\Support\Str::isUlid($requestId) ? $requestId : (string) \Illuminate\Support\Str::ulid())
                <form method="post" action="/console/affiliate/withdraw">@csrf<input type="hidden" name="ui_form" value="affiliate-withdraw"><input type="hidden" name="request_id" value="{{ $withdrawalRequest }}"><label>金额（两位小数，CNY）<input name="amount" value="{{ $oldText('amount', 'affiliate-withdraw') }}" inputmode="decimal" pattern="[0-9]+\.[0-9]{2}" required placeholder="10.00"></label><button class="console-button">申请转钱包</button></form>
            @endif

    </x-console.card>
    <x-console.card title="佣金记录"><div class="table-scroll" role="region" aria-label="佣金记录" tabindex="0"><table class="console-table"><thead><tr><th scope="col">账单</th><th scope="col" class="console-money">金额 / 费率</th><th scope="col">状态 / 成熟时间</th></tr></thead><tbody>
        @forelse($commissions as $commission)<tr><td>#{{ $commission->invoice_id }}</td><td class="console-money">{{ $commission->amount }} CNY / {{ $commission->rate_snapshot }}%</td><td><x-console.badge :tone="$commission->status === 'available' ? 'success' : 'neutral'">{{ $commission->status }}</x-console.badge><p class="console-muted">{{ $commission->available_at }}</p></td></tr>@empty<tr><td colspan="3" class="console-empty">暂无佣金。</td></tr>@endforelse
    </tbody></table></div>{{ $commissions->links('sales.pagination') }}</x-console.card>
    <x-console.card title="转钱包申请"><div class="table-scroll" role="region" aria-label="转钱包申请记录" tabindex="0"><table class="console-table"><thead><tr><th scope="col">申请</th><th scope="col" class="console-money">金额</th><th scope="col">去向 / 状态</th><th scope="col">申请 / 审核时间</th></tr></thead><tbody>
        @forelse($withdrawals as $withdrawal)<tr><td>#{{ $withdrawal->id }}</td><td class="console-money">{{ $withdrawal->amount }} CNY</td><td>{{ $withdrawal->destination }} · <x-console.badge :tone="$withdrawal->status === 'approved' ? 'success' : 'neutral'">{{ $withdrawal->status }}</x-console.badge></td><td>{{ $withdrawal->created_at }}<p class="console-muted">{{ $withdrawal->processed_at ?? '待审核' }}</p></td></tr>@empty<tr><td colspan="4" class="console-empty">暂无转钱包申请。</td></tr>@endforelse
    </tbody></table></div>{{ $withdrawals->links('sales.pagination') }}</x-console.card>
    <x-console.card title="退款追回核对">
        @if($receivableCheck)<p role="alert">应收历史待完整核对，核验完成前资金操作暂停。</p>@if(isset($receivableCheck['receivable_check_url'][0]))<p><a href="{{ $receivableCheck['receivable_check_url'][0] }}">查看完整核验进度</a></p>@endif @endif
        @if($receivableTotals)<p>全部应收：本金 {{ $receivableTotals['principal'] }}，已清偿 {{ $receivableTotals['settled'] }}，已冲正 {{ $receivableTotals['reversed'] }}，剩余 {{ $receivableTotals['remaining'] }} CNY。</p>@endif
        @forelse($receivableRoots as $root)
            @php($entry = $receivables->get($root->id))
            <p>流水 #{{ $root->id }} / 原账单 #{{ $root->invoice_id }} / 剩余应收 {{ $entry === null ? '待完整核对' : ($entry['valid'] ? $entry['remaining'].' CNY' : '证据异常，请联系财务') }}</p>
        @empty<p class="console-empty">暂无退款追回记录。</p>@endforelse
        {{ $receivableRoots->links('sales.pagination') }}
    </x-console.card>
@elseif($section === 'tickets')
    <x-console.card title="新建工单">

            <form method="get" action="/console/tickets"><label>查找关联服务（名称、IP 或完整 ID）<input name="service_q" value="{{ $serviceSearch }}" maxlength="100"></label><div class="console-actions"><button class="console-button is-secondary">查找服务</button><a href="/console/tickets">清除搜索</a></div></form>
            <p class="console-muted">关联服务最多显示 50 个；可搜索较早的服务。@if($instanceHasMore) 当前匹配结果较多，请缩小搜索范围。@endif</p>
            <form method="post" action="/console/tickets" class="console-form-grid">@csrf<input type="hidden" name="ui_form" value="ticket-create">
                <label>主题<input name="subject" value="{{ $oldText('subject', 'ticket-create') }}" maxlength="255" required></label>
                <label>关联服务<select name="instance_id"><option value="">不关联</option>@foreach($instances as $instance)<option value="{{ $instance->id }}" @selected($oldText('instance_id', 'ticket-create') === (string) $instance->id)>#{{ $instance->id }} {{ $instance->display_name }}</option>@endforeach</select></label>
                <label class="console-span-full">问题描述<textarea name="body" required maxlength="20000" rows="7">{{ $oldText('body', 'ticket-create') }}</textarea></label>
                <div class="console-actions console-span-full"><button class="console-button">提交工单</button></div>
            </form>

    </x-console.card>
    <x-console.card title="工单记录"><div class="table-scroll" role="region" aria-label="工单记录" tabindex="0"><table class="console-table"><thead><tr><th scope="col">工单</th><th scope="col">状态</th><th scope="col">更新时间</th><th scope="col">操作</th></tr></thead><tbody>
        @forelse($tickets as $ticket)<tr><td><a href="/console/tickets/{{ $ticket->id }}">#{{ $ticket->id }} {{ $ticket->subject }}</a></td><td><x-console.badge :tone="$ticket->status === 'closed' ? 'neutral' : ($ticket->status === 'answered' ? 'success' : 'warning')">{{ $ticket->status }}</x-console.badge></td><td>{{ $ticket->updated_at }}</td><td><a href="/console/tickets/{{ $ticket->id }}">查看工单</a></td></tr>@empty<tr><td colspan="4" class="console-empty">暂无工单。</td></tr>@endforelse
    </tbody></table></div>{{ $tickets->links('sales.pagination') }}</x-console.card>
@elseif($section === 'ticket')
    <x-console.card :title="'#'.$ticket->id.' '.$ticket->subject"><p><x-console.badge :tone="$ticket->status === 'closed' ? 'neutral' : ($ticket->status === 'answered' ? 'success' : 'warning')">{{ $ticket->status }}</x-console.badge> · 服务 #{{ $ticket->instance_id ?? '—' }}</p></x-console.card>
    <x-console.card title="沟通记录">
        @forelse($replies as $reply)<article><p><strong>{{ $reply->is_admin ? '客服' : '客户' }} #{{ $reply->user_id }}</strong> <span class="console-muted">· {{ $reply->created_at }}</span></p><div style="white-space:pre-wrap">{{ $reply->body }}</div></article>@empty<p class="console-empty">暂无回复。</p>@endforelse
        {{ $replies->links('sales.pagination') }}
    </x-console.card>
    <x-console.card :title="$ticket->status === 'closed' ? '工单已关闭' : '回复工单'">
        <form method="post" action="/console/tickets/{{ $ticket->id }}">@csrf<input type="hidden" name="ui_form" value="ticket-{{ $ticket->id }}">
            @if($ticket->status !== 'closed')<label>回复<textarea name="body" maxlength="20000" rows="6">{{ $oldText('body', 'ticket-'.$ticket->id) }}</textarea></label><div class="console-actions"><button class="console-button" name="operation" value="reply">发送回复</button><button class="console-button is-secondary" name="operation" value="close">关闭工单</button></div>@else<button class="console-button" name="operation" value="reopen">明确重开工单</button>@endif
        </form>
    </x-console.card>
@elseif($section === 'downloads')
    <p class="console-muted">资源按访问要求授权。本地文件经系统下载；外部资源将跳转至第三方站点。</p>
    <div class="console-grid">
        @forelse($resources as $resource)
            <x-console.card :title="$resource->title">
                <p>{{ $resource->version }} <x-console.badge>{{ $resource->access_level->value }}</x-console.badge></p><p class="console-muted">{{ $resource->category }} · {{ $resource->file_size ?? '—' }} bytes</p><p style="white-space:pre-wrap">{{ $resource->description }}</p>@if($resource->sha256)<p class="console-muted">SHA256 <code>{{ $resource->sha256 }}</code></p>@endif<a class="console-button is-secondary" href="/downloads/{{ $resource->id }}">授权下载</a>
            </x-console.card>
        @empty<p class="console-empty">暂无资源。</p>@endforelse
    </div>
    {{ $resources->links('sales.pagination') }}
@endif
@endsection
