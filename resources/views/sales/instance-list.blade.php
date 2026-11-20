<div class="table-scroll" role="region" aria-label="服务记录" tabindex="0"><table class="console-table"><thead><tr><th scope="col">服务 / 商品</th><th scope="col">状态 / IP</th><th scope="col" class="console-money">计费</th><th scope="col">到期时间</th></tr></thead><tbody>
@forelse($instances as $row)<tr>
    <td><a href="/console/instances/{{ $row->id }}"><strong>{{ $row->display_name }}</strong></a><p class="console-muted">#{{ $row->id }} · {{ $row->product?->name ?? '历史商品' }}</p>@if(app(\App\Services\DriverManager::class)->oneTimeDelivery($row) !== null)<a href="/console/instances/{{ $row->id }}/archive">归档交付权益</a>@endif</td>
    <td><x-console.badge :tone="match($row->status) { \App\Enums\InstanceStatus::ACTIVE => 'success', \App\Enums\InstanceStatus::PENDING, \App\Enums\InstanceStatus::SUSPENDED => 'warning', \App\Enums\InstanceStatus::FRAUD, \App\Enums\InstanceStatus::PROVISION_FAILED => 'danger', default => 'neutral' }">{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$row->status->value] }}</x-console.badge><p class="console-muted">{{ $row->primary_ip ?? '—' }}</p></td>
    <td class="console-money">@if($row->billing_cycle->isRenewable())<strong>{{ $row->recurring_amount }} {{ $row->currency }}</strong>@endif<p class="console-muted">{{ $row->billing_cycle->label() }}</p></td>
    <td>{{ $row->next_due_at?->format('Y-m-d H:i') ?? '—' }}</td>
</tr>
@empty<tr><td colspan="4" class="console-empty">暂无符合条件的服务。</td></tr>@endforelse
</tbody></table></div>
