@extends('sales.layout')
@section('title', ['coupons'=>'我的优惠券','affiliate'=>'推广与佣金','tickets'=>'我的工单','ticket'=>'工单详情','downloads'=>'下载中心'][$section])
@section('content')
<h1>@yield('title')</h1>
@if($section === 'coupons')
<section><form method="post" action="/console/coupons">@csrf<label>优惠券码<input name="code" required maxlength="64"></label><button>领取优惠券</button></form><p>领取不占用额度；在新购或新建续费账单填写券码。首轮仅 CNY，取消和退款不恢复次数。</p></section>
@forelse($coupons as $coupon)<section><h2>{{ $coupon->code }}</h2><p>{{ $coupon->type === 'percent' ? '减免百分比' : '固定减免' }} {{ $coupon->value }}{{ $coupon->type === 'percent' ? '%' : ' CNY' }} · 最低订单额 {{ $coupon->min_order_amount }} CNY</p><p>{{ $coupon->is_active ? '启用' : '停用' }} · 有效至 {{ $coupon->valid_until ?? '不限' }} · 每人 {{ $coupon->per_user_limit }} 次</p></section>@empty<p>尚未领取优惠券。</p>@endforelse
{{ $coupons->links() }}
<h2>最近使用记录</h2>@foreach($usages as $usage)<p><a href="/console/invoices/{{ $usage->invoice_id }}">账单 #{{ $usage->invoice_id }}</a> · 减免 {{ \App\Support\Money::of($usage->discount_amount) }} CNY</p>@endforeach
@elseif($section === 'affiliate')
<p>首轮仅单层 CNY 新购计佣，以折后账单总额为基础。默认冻结 7 天，最终以建单快照为准。可选择转入本站钱包或申请人工外部转账。</p>
<section><h2>推广链接</h2><a href="{{ $referralUrl }}">{{ url($referralUrl) }}</a><p>仅新注册账户归因；已登录用户不会改变归属。</p></section>
<p><a href="/console/affiliate/external">申请外部提现 / 查看出款进度</a></p>
<section><h2>转入本站钱包</h2><p>账面余额 {{ $balance }} · 申请占用 {{ $pending }} · 可申请 {{ $available }} CNY</p>
@if($hasReceivable)<p class="error">存在退款应收，暂停提现；请联系财务核对原流水，不能用新佣金自动抵销。</p>@else
<form method="post" action="/console/affiliate/withdraw">@csrf<input type="hidden" name="request_id" value="{{ $requestId }}"><label>金额（两位小数，CNY）<input name="amount" inputmode="decimal" pattern="[0-9]+\.[0-9]{2}" required placeholder="10.00"></label><button>申请转钱包</button></form>
@endif
</section>
<h2>佣金记录</h2><div class="table-scroll"><table><thead><tr><th>账单</th><th>金额 / 费率</th><th>状态 / 成熟时间</th></tr></thead><tbody>@forelse($commissions as $commission)<tr><td>#{{ $commission->invoice_id }}</td><td>{{ $commission->amount }} CNY / {{ $commission->rate_snapshot }}%</td><td>{{ $commission->status }} / {{ $commission->available_at }}</td></tr>@empty<tr><td colspan="3">暂无佣金。</td></tr>@endforelse</tbody></table></div>{{ $commissions->links() }}
<h2>转钱包申请</h2>@foreach($withdrawals as $withdrawal)<section>#{{ $withdrawal->id }} · {{ $withdrawal->amount }} CNY · {{ $withdrawal->destination }} · {{ $withdrawal->status }}<p>申请 {{ $withdrawal->created_at }} / 审核 {{ $withdrawal->processed_at ?? '待审核' }}</p></section>@endforeach{{ $withdrawals->links() }}
@if($hasReceivable)<h2>退款追回核对</h2>@foreach($receivables as $entry)@if(!$entry['valid'] || \App\Support\Money::gt($entry['remaining'], '0.00'))<p>流水 #{{ $entry['id'] }} / 原账单 #{{ $entry['invoice_id'] }} / 剩余应收 {{ $entry['valid'] ? $entry['remaining'].' CNY' : '证据异常，请联系财务' }}</p>@endif
@endforeach
@endif
@elseif($section === 'tickets')
<section><h2>新建工单</h2>
<form method="get" action="/console/tickets"><label>查找关联服务（名称、IP 或完整 ID）<input name="service_q" value="{{ $serviceSearch }}" maxlength="100"></label><button>查找服务</button> <a href="/console/tickets">清除搜索</a></form>
<p>关联服务最多显示 50 个；可搜索较早的服务。@if($instanceHasMore) 当前匹配结果较多，请缩小搜索范围。@endif</p>
<form method="post" action="/console/tickets">@csrf<label>主题<input name="subject" value="{{ old('subject') }}" maxlength="255" required></label><label>关联服务<select name="instance_id"><option value="">不关联</option>@foreach($instances as $instance)<option value="{{ $instance->id }}" @selected((string) old('instance_id') === (string) $instance->id)>#{{ $instance->id }} {{ $instance->display_name }}</option>@endforeach</select></label><label>问题描述<textarea name="body" required maxlength="20000" rows="7" style="width:100%">{{ old('body') }}</textarea></label><button>提交工单</button></form></section>
@forelse($tickets as $ticket)<section><a href="/console/tickets/{{ $ticket->id }}">#{{ $ticket->id }} {{ $ticket->subject }}</a> · {{ $ticket->status }}<p>{{ $ticket->updated_at }}</p></section>@empty<p>暂无工单。</p>@endforelse{{ $tickets->links() }}
@elseif($section === 'ticket')
<p><a href="/console/tickets">返回工单列表</a></p><h2>#{{ $ticket->id }} {{ $ticket->subject }}</h2><p>{{ $ticket->status }} · 服务 #{{ $ticket->instance_id ?? '—' }}</p>
@foreach($replies as $reply)<section><strong>{{ $reply->is_admin ? '客服' : '客户' }} #{{ $reply->user_id }}</strong> · {{ $reply->created_at }}<div style="white-space:pre-wrap">{{ $reply->body }}</div></section>@endforeach{{ $replies->links() }}
<section><form method="post" action="/console/tickets/{{ $ticket->id }}">@csrf
@if($ticket->status !== 'closed')<label>回复<textarea name="body" maxlength="20000" rows="6" style="width:100%"></textarea></label><button name="operation" value="reply">发送回复</button><button name="operation" value="close">关闭工单</button>@else<button name="operation" value="reopen">明确重开工单</button>@endif
</form></section>
@elseif($section === 'downloads')
<p>资源按访问要求授权。本地文件经系统下载；外部资源将跳转至第三方站点。</p>
@forelse($resources as $resource)<section><h2>{{ $resource->title }} {{ $resource->version }}</h2><p>{{ $resource->category }} · {{ $resource->access_level->value }} · {{ $resource->file_size ?? '—' }} bytes</p><p style="white-space:pre-wrap">{{ $resource->description }}</p>@if($resource->sha256)<p>SHA256 <code>{{ $resource->sha256 }}</code></p>@endif<a href="/downloads/{{ $resource->id }}">授权下载</a></section>@empty<p>暂无资源。</p>@endforelse{{ $resources->links() }}
@endif
@endsection
