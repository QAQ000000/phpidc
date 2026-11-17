<div class="table-scroll"><table><thead><tr><th>服务 / 商品</th><th>状态 / IP</th><th>计费</th><th>到期时间</th></tr></thead><tbody>
@forelse($instances as $row)<tr><td><a href="/console/instances/{{ $row->id }}">{{ $row->display_name }}</a><br>{{ $row->product?->name ?? '历史商品' }}</td><td>{{ \App\Support\CustomerLabels::INSTANCE_STATUSES[$row->status->value] }}<br>{{ $row->primary_ip ?? '—' }}</td><td>{{ $row->billing_cycle->label() }}@if($row->billing_cycle->isRenewable())<br>{{ $row->recurring_amount }} {{ $row->currency }}@endif</td><td>{{ $row->next_due_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
@empty<tr><td colspan="4">暂无符合条件的服务。</td></tr>@endforelse
</tbody></table></div>
