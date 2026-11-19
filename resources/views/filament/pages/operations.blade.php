<x-filament-panels::page>
@include('filament.inventory-feedback')
@if($section === 'coupons')
<section class="inventory-box"><p>百分比优惠填写 10.00 表示减免 10%。支持 CNY 新购和新建续费账单，既有订单金额保持不变。</p></section>
@can('promotion.manage')
<section class="inventory-box"><h2>{{ $editing ? '编辑优惠券 #'.$editing->id : '创建优惠券' }}</h2><form class="inventory-form" method="post" action="/admin/operations/config/coupon">@csrf
<input type="hidden" name="id" value="{{ $editing?->id }}"><label>券码<input name="code" required maxlength="64" value="{{ $editing?->code }}"></label><label>类型<select name="type">@foreach(['percent','fixed'] as $type)<option @selected($editing?->type === $type)>{{ $type }}</option>@endforeach</select></label>
<label>优惠值<input name="value" required value="{{ $editing?->value ?? '10.00' }}"></label><label>最低金额<input name="min_order_amount" required value="{{ $editing?->min_order_amount ?? '0.00' }}"></label>
<label>适用账单<select name="applies_to">@foreach(['both','new_order','renew'] as $type)<option @selected($editing?->applies_to === $type)>{{ $type }}</option>@endforeach</select></label>
@include('filament.operations-product-selector', ['selectedProductIds' => $editing?->product_ids])
<label>总额度（空为不限）<input type="number" name="total_quota" min="0" value="{{ $editing?->total_quota }}"></label><label>每人限次<input type="number" name="per_user_limit" min="1" required value="{{ $editing?->per_user_limit ?? 1 }}"></label>
<label>生效时间<input type="datetime-local" name="valid_from" value="{{ $editing?->valid_from?->format('Y-m-d\TH:i') }}"></label><label>截止时间<input type="datetime-local" name="valid_until" value="{{ $editing?->valid_until?->format('Y-m-d\TH:i') }}"></label>
@php
    $claimConditions = old('claim_conditions', $editing?->claim_conditions ?? []);
    $requiredChannels = data_get($claimConditions, 'require_bound_channels', []);
@endphp
<details class="inventory-box" style="width:100%"><summary>领取条件（可选）</summary>
<label>注册天数大于<input name="claim_conditions[registered_days_gt]" type="number" min="0" value="{{ data_get($claimConditions, 'registered_days_gt') }}" placeholder="不限"></label>
<label>注册天数小于<input name="claim_conditions[registered_days_lt]" type="number" min="1" value="{{ data_get($claimConditions, 'registered_days_lt') }}" placeholder="不限"></label>
<input type="hidden" name="claim_conditions[require_kyc]" value="0"><label><input type="checkbox" name="claim_conditions[require_kyc]" value="1" @checked(data_get($claimConditions, 'require_kyc', false))>须完成实名认证</label>
<fieldset><legend>须绑定的联系方式</legend>@foreach(['email' => '邮箱', 'sms' => '手机', 'telegram' => 'Telegram'] as $channel => $channelLabel)<label><input type="checkbox" name="claim_conditions[require_bound_channels][]" value="{{ $channel }}" @checked(is_array($requiredChannels) && in_array($channel, $requiredChannels, true))>{{ $channelLabel }}</label>@endforeach</fieldset>
</details>
<label>状态<select name="is_active"><option value="1" @selected($editing?->is_active !== false)>启用</option><option value="0" @selected($editing?->is_active === false)>停用</option></select></label><label>备注（可选）<input name="reason" maxlength="255" value="{{ old('reason') }}"></label><button class="inventory-button">保存</button></form></section>
<section class="inventory-box"><h2>定向发放领取资格</h2><form class="inventory-form" method="post" action="/admin/operations/coupons/grant">@csrf<label>用户 ID<input name="user_id" type="number" min="1" required></label><label>券码<input name="code" required></label><label>备注（可选）<input name="reason" maxlength="255"></label><button class="inventory-button">发放</button></form></section>
@endcan
<div class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>券码 / 类型</th><th>优惠 / 最低额</th><th>核销 / 总额</th><th>有效期 / 操作</th></tr></thead><tbody>@forelse($records as $record)<tr><td>{{ $record->code }} / {{ $record->type }}</td><td>{{ $record->value }} / {{ $record->min_order_amount }}</td><td>{{ $record->used_count }} / {{ $record->total_quota ?? '不限' }}</td><td>{{ $record->is_active ? '启用' : '停用' }}<br>{{ $record->valid_until ?? '不限' }}<br><a href="?edit={{ $record->id }}">查看 / 编辑</a></td></tr>@empty<tr><td colspan="4">暂无优惠券。</td></tr>@endforelse</tbody></table>{{ $records->links() }}</div>
@elseif($section === 'affiliates')
<section class="inventory-box"><p>只对 CNY 新购计佣。priority 降序、ID 升序；未绑定商品表示全局规则。新规则不改变已建单快照。</p></section>
@can('affiliate.manage')
<section class="inventory-box"><form class="inventory-form" method="post" action="/admin/operations/config/rule">@csrf<input type="hidden" name="id" value="{{ $editing?->id }}"><label>规则名称<input name="name" required value="{{ old('name', $editing?->name) }}"></label><label>费率 %<input name="commission_rate" required value="{{ old('commission_rate', $editing?->commission_rate ?? '10.00') }}"></label><label>优先级<input name="priority" type="number" min="0" required value="{{ old('priority', $editing?->priority ?? 0) }}"></label>
@include('filament.operations-product-selector', ['selectedProductIds' => $productIds, 'allWhenEmpty' => true])
<label>状态<select name="is_active"><option value="1" @selected($editing?->is_active !== false)>启用</option><option value="0" @selected($editing?->is_active === false)>停用</option></select></label><label>备注（可选）<input name="reason" maxlength="255" value="{{ old('reason') }}"></label><button class="inventory-button">保存规则</button></form></section>
@endcan
@foreach($records as $record)<section class="inventory-box">#{{ $record->id }} {{ $record->name }} / {{ $record->commission_rate }}% / priority {{ $record->priority }} / {{ $record->is_active ? '启用' : '停用' }} · <a href="?edit={{ $record->id }}">查看 / 编辑</a></section>@endforeach{{ $records->links() }}
<section class="inventory-box"><h2>佣金明细</h2><form class="inventory-form" method="get"><label>推广人 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><button class="inventory-button">筛选</button></form></section>
<div class="inventory-box inventory-scroll"><table class="inventory-table"><thead><tr><th>佣金 / 账单</th><th>推广人 / 买家</th><th>金额 / 费率</th><th>状态 / 成熟</th></tr></thead><tbody>@foreach($commissions as $commission)<tr><td>#{{ $commission->id }} / #{{ $commission->invoice_id }}</td><td>#{{ $commission->referrer_id }} / #{{ $commission->buyer_id }}</td><td>{{ $commission->amount }} / {{ $commission->rate_snapshot }}%</td><td>{{ $commission->status }} / {{ $commission->available_at }}</td></tr>@endforeach</tbody></table>{{ $commissions->links() }}</div>
@elseif($section === 'payouts')
<section class="inventory-box"><p>本页审核转本站钱包；<a class="inventory-link" href="/admin/external-payouts">外部提现</a>单独处理。pending 占用可申请额度。退款可追回推广余额，审核时再次校验。应收未清偿时禁止批准。</p><form class="inventory-form" method="get"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>状态<select name="status"><option value="">全部</option>@foreach(['pending','approved','rejected'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label><button class="inventory-button">筛选</button></form></section>
@forelse($records as $record)<section class="inventory-box"><h2>申请 #{{ $record->id }} · 用户 #{{ $record->user_id }}</h2><p>{{ $record->amount }} CNY / {{ $record->destination }} / {{ $record->status }}</p><p>当前推广余额 {{ $payoutUsers->get($record->user_id)?->affiliate_balance ?? '账户不可用' }} / 申请占用 {{ $payoutFunds[$record->user_id]['pending'] ?? '—' }} CNY</p>
@if($payoutFunds[$record->user_id]['checking'] ?? true)<p class="inventory-note">完整应收历史待核对，当前未判定为无债。<a class="inventory-link" href="{{ $payoutFunds[$record->user_id]['check_url'] ?? '/admin/finance/receivables?user_id='.$record->user_id }}">查看完整核验或全部应收</a></p>@endif
@if($payoutFunds[$record->user_id]['receivable'] ?? false)<p class="inventory-note">应收或核验尚未结清，批准前须完整核对。以下最多展示本用户两笔，<a class="inventory-link" href="/admin/finance/receivables?user_id={{ $record->user_id }}">查看全部应收</a>：</p>@foreach($receivables->where('user_id', $record->user_id) as $entry)@if(!$entry['valid'] || \App\Support\Money::gt($entry['remaining'], '0.00'))<p>流水 #{{ $entry['id'] }} / 原账单 #{{ $entry['invoice_id'] }} / 剩余应收 {{ $entry['valid'] ? $entry['remaining'].' CNY' : '证据异常，禁止批准' }} · <a class="inventory-link" href="/admin/finance/receivables?clawback={{ $entry['id'] }}">核对清偿</a></p>@endif
@endforeach
@endif
<p>审核人 #{{ $record->processed_by ?? '—' }} / {{ $record->processed_at ?? '待审核' }}</p>
@can('finance.payout')
@if($record->status === 'pending' && $record->destination === 'wallet')<form class="inventory-form" method="post" action="/admin/operations/payouts/{{ $record->id }}" x-data="{ decision: @js((int) old('record_id') === $record->id ? old('decision', 'reject') : 'reject') }">@csrf<label>审核备注（驳回时必填）<input name="reason" value="{{ (int) old('record_id') === $record->id ? old('reason') : '' }}" x-bind:required="decision === 'reject'" maxlength="255"><input type="hidden" name="record_id" value="{{ $record->id }}"></label><label>决定<select name="decision" x-model="decision"><option value="reject" @selected((int) old('record_id') === $record->id && old('decision') === 'reject')>驳回</option><option value="approve" @selected((int) old('record_id') === $record->id && old('decision') === 'approve')>批准转钱包</option></select></label><label><span><input type="checkbox" name="confirm" value="1" required> 已核查金额与退款应收</span></label><button class="inventory-button">确认处理</button></form>@endif
@endcan
</section>@empty<p>暂无申请。</p>@endforelse{{ $records->links() }}
@elseif($section === 'support')
<section class="inventory-box"><form class="inventory-form" method="get"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>状态<select name="status"><option value="">全部</option>@foreach(['open','answered','closed'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label><button class="inventory-button">筛选</button></form></section>
@foreach($records as $record)<section class="inventory-box"><a href="?edit={{ $record->id }}">#{{ $record->id }} {{ $record->subject }}</a> / 客户 #{{ $record->user_id }} / {{ $record->status }}</section>@endforeach{{ $records->links() }}
@if($ticket)<section class="inventory-box"><h2>#{{ $ticket->id }} {{ $ticket->subject }}</h2><p>{{ $ticket->status }} / 服务 #{{ $ticket->instance_id ?? '—' }}</p></section>
@foreach($replies as $reply)<section class="inventory-box"><p>{{ $reply->is_admin ? '客服' : '客户' }} #{{ $reply->user_id }} / {{ $reply->created_at }}</p><div style="white-space:pre-wrap">{{ $reply->body }}</div></section>@endforeach{{ $replies->links() }}
@can('support.reply')
<section class="inventory-box"><form class="inventory-form" method="post" action="/admin/operations/tickets/{{ $ticket->id }}">@csrf<label>正文<textarea name="body" maxlength="20000" rows="6">{{ old('body') }}</textarea></label><label>备注（可选）<input name="reason" maxlength="255" value="{{ old('reason') }}"></label>
@if($ticket->status === 'closed')<button class="inventory-button" name="operation" value="reopen">重开工单</button>@else<button class="inventory-button" name="operation" value="reply">客服回复</button><button class="inventory-button" name="operation" value="close">关闭工单</button>@endif
</form></section>
@endcan
@endif
@elseif($section === 'notifications')
<section class="inventory-box"><p><a class="inventory-link" href="/admin/notification-outbox">查看通知记录和待核对结果</a>。正文可使用双花括号变量：{{ implode('、', $variables) }}。短信需要已审核模板及可用供应商。</p></section>
@can('notification.manage')
@php
    $mappingRows = old('mapping_rows');
    if (!is_array($mappingRows)) {
        $mappingRows = [];
        foreach ($editing?->variable_mapping ?? [] as $externalName => $variable) {
            $mappingRows[] = ['name' => (string) $externalName, 'value' => $variable];
        }
    }
    $mappingRows = $mappingRows ?: [['name' => '', 'value' => '']];
@endphp
<section class="inventory-box"><form class="inventory-form" method="post" action="/admin/operations/config/template" x-data="{ channel: @js(old('channel', $editing?->channel ?? 'email')), rows: @js($mappingRows) }">@csrf
<input type="hidden" name="id" value="{{ $editing?->id }}"><label>事件<select name="event_key">@foreach($events as $event)<option @selected(old('event_key', $editing?->event_key) === $event)>{{ $event }}</option>@endforeach</select></label>
<label>渠道<select name="channel" x-model="channel">@foreach(['email' => '邮件', 'sms' => '短信', 'telegram' => 'Telegram'] as $channel => $channelLabel)<option value="{{ $channel }}" @selected(old('channel', $editing?->channel ?? 'email') === $channel)>{{ $channelLabel }}</option>@endforeach</select></label>
<label>主题<input name="subject" value="{{ old('subject', $editing?->subject) }}" maxlength="255"></label><label>正文<textarea name="body_markdown" rows="6" required>{{ old('body_markdown', $editing?->body_markdown) }}</textarea></label>
<fieldset class="inventory-box" style="width:100%" x-show="channel === 'sms'" x-bind:disabled="channel !== 'sms'"><legend>短信模板</legend>
<label>已审核短信模板 ID<input name="external_template_id" value="{{ old('external_template_id', $editing?->external_template_id) }}"></label>
<input type="hidden" name="mapping_rows_present" value="1">
<template x-for="(row, index) in rows" :key="index"><div class="inventory-form">
<label>供应商变量名<input :name="'mapping_rows[' + index + '][name]'" x-model="row.name" maxlength="255" placeholder="例如：amount"></label>
<label>使用的内容<select :name="'mapping_rows[' + index + '][value]'" x-model="row.value"><option value="">选择变量</option>@foreach($variables as $variable)<option value="{{ $variable }}">{{ $variable }}</option>@endforeach</select></label>
<button type="button" class="inventory-button" @click="rows.splice(index, 1)">移除变量</button></div></template>
<button type="button" class="inventory-button" @click="rows.push({ name: '', value: '' })">添加变量映射</button>
</fieldset>
<label>状态<select name="is_enabled"><option value="1" @selected(old('is_enabled', $editing?->is_enabled) !== false && old('is_enabled', $editing?->is_enabled) !== '0')>启用</option><option value="0" @selected(old('is_enabled', $editing?->is_enabled) === false || old('is_enabled', $editing?->is_enabled) === '0')>停用</option></select></label><label>备注（可选）<input name="reason" maxlength="255" value="{{ old('reason') }}"></label><button class="inventory-button">保存模板</button></form></section>
@endcan
@foreach($records as $record)<section class="inventory-box">{{ $record->event_key }} / {{ $record->channel }} / {{ $record->is_enabled ? '启用' : '停用' }} · <a href="?edit={{ $record->id }}">查看 / 编辑</a></section>@endforeach{{ $records->links() }}
<div class="inventory-box inventory-scroll"><h2>投递日志（不显示收件人和异常原文）</h2><p>临时拒绝自动退避，最多尝试三次；结果不确定须先核对供应商，禁止直接重放。最终耗尽请在任务监控查看失败队列。</p><form method="get" class="inventory-form"><label>用户 ID<input name="user_id" type="number" min="1" value="{{ request('user_id') }}"></label><label>投递状态<select name="delivery_status"><option value="">全部</option>@foreach(['failed', 'simulated', 'sent', 'skipped', 'disabled'] as $state)<option @selected(request('delivery_status') === $state)>{{ $state }}</option>@endforeach</select></label><button class="inventory-button">筛选投递</button></form><table class="inventory-table"><thead><tr><th>ID / 用户</th><th>事件 / 渠道</th><th>状态 / 时间</th></tr></thead><tbody>@foreach($logs as $log)<tr><td>#{{ $log->id }} / #{{ $log->user_id }}</td><td>{{ $log->event_key }} / {{ $log->channel }}</td><td>{{ $log->status }} / {{ $log->created_at }}<br>{{ $log->delivery_note }}</td></tr>@endforeach</tbody></table>{{ $logs->links() }}</div>
@elseif($section === 'downloads')
<section class="inventory-box"><p>本地文件保存在私有磁盘。下载量是接受请求数，不能证明传输完成；受限外链只在授权后跳转，外部链接可能被分享。</p></section>
@can('download.manage')
<section class="inventory-box"><form class="inventory-form" method="post" enctype="multipart/form-data" action="/admin/operations/downloads" x-data="{ storage: @js(old('storage_type', $editing?->storage_type ?? 'local')) }">@csrf
<input type="hidden" name="id" value="{{ $editing?->id }}"><label>标题<input name="title" required maxlength="255" value="{{ old('title', $editing?->title) }}"></label><label>分类<input name="category" required maxlength="64" value="{{ old('category', $editing?->category ?? 'general') }}"></label><label>版本<input name="version" maxlength="64" value="{{ old('version', $editing?->version) }}"></label><label>说明<textarea name="description">{{ old('description', $editing?->description) }}</textarea></label>
<label>存储方式<select name="storage_type" x-model="storage"><option value="local" @selected(old('storage_type', $editing?->storage_type ?? 'local') === 'local')>上传文件</option><option value="external_url" @selected(old('storage_type', $editing?->storage_type) === 'external_url')>外部链接</option></select></label>
<fieldset x-show="storage === 'local'" x-bind:disabled="storage !== 'local'"><label>私有文件（最大 50 MB；编辑留空保留）<input type="file" name="file"></label></fieldset>
<fieldset x-show="storage === 'external_url'" x-bind:disabled="storage !== 'external_url'"><label>HTTPS 外链<input name="external_url" type="url" value="{{ old('external_url', $editing?->external_url) }}"></label></fieldset>
<label>访问要求<select name="access_level">@foreach(\App\Enums\DownloadAccessLevel::cases() as $level)<option value="{{ $level->value }}" @selected(old('access_level', $editing?->access_level?->value) === $level->value)>{{ match($level) { \App\Enums\DownloadAccessLevel::PUBLIC => '公开下载', \App\Enums\DownloadAccessLevel::AUTHENTICATED => '登录后下载', \App\Enums\DownloadAccessLevel::ACTIVE_CUSTOMER => '有效服务客户' } }}</option>@endforeach</select></label><label>排序<input name="sort_order" type="number" min="0" value="{{ old('sort_order', $editing?->sort_order ?? 0) }}" required></label>
<label>状态<select name="is_active"><option value="1" @selected($editing?->is_active !== false)>启用</option><option value="0" @selected($editing?->is_active === false)>停用</option></select></label><label>备注（可选）<input name="reason" maxlength="255" value="{{ old('reason') }}"></label><button class="inventory-button">保存资源</button></form></section>
@endcan
@forelse($records as $record)<section class="inventory-box"><h2>{{ $record->title }} {{ $record->version }}</h2><p>#{{ $record->id }} / {{ $record->access_level->value }} / {{ $record->is_active ? '启用' : '停用' }} / 请求 {{ $record->download_count }}</p><p>{{ $record->file_size ?? '—' }} bytes / SHA256 {{ $record->sha256 ?? '—' }}</p><a href="?edit={{ $record->id }}">查看 / 编辑</a></section>@empty<p>暂无资源。</p>@endforelse{{ $records->links() }}
@endif
</x-filament-panels::page>
