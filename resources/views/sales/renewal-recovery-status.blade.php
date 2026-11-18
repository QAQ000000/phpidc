@if($recoveryInfo)
<section><h2>续费账单与恢复记录</h2>
@foreach($recoveryInfo['invoices'] as $member)<p><a href="/console/invoices/{{ $member->id }}">{{ $member->invoice_no }}</a> · {{ $member->status->value }}</p>@endforeach
@if($recoveryInfo['current']->id !== $invoice->id)<p>此账单已有后续记录，<a href="/console/invoices/{{ $recoveryInfo['current']->id }}">查看当前续费账单</a>。</p>
@elseif($recoveryInfo['eligible'])
<p>本账单已过期，原钱包冻结款已核对。重新核价后可确认恢复续费；旧优惠券次数不返还，旧单迟到付款会存入钱包。</p>
<form method="post" action="/console/invoices/{{ $invoice->id }}/renewal-recovery/quote">@csrf
<input type="hidden" name="request_id" value="{{ \Illuminate\Support\Str::ulid() }}">
<label>优惠券<input name="coupon_code" maxlength="64"></label>
<label><input type="checkbox" name="use_credit" value="1">本次使用钱包余额</label>
<label>外部支付方式<select name="gateway"><option value="epay_wechat">微信支付</option><option value="epay_alipay">支付宝</option></select></label>
<button>重新核价并恢复续费</button></form>
@else<p>恢复提示：{{ $recoveryInfo['reason'] }}</p>@endif
</section>
@endif
