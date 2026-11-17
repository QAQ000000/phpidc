<div class="table-scroll"><table><thead><tr><th>账单 / 商品</th><th>类型 / 状态</th><th>金额</th><th>创建时间</th></tr></thead><tbody>
@forelse($invoices as $row)<tr><td><a href="/console/invoices/{{ $row->id }}">{{ $row->invoice_no }}</a><br>{{ $row->product?->name ?? '历史商品' }}</td><td>{{ \App\Support\CustomerLabels::INVOICE_TYPES[$row->type->value] }}<br>{{ \App\Support\CustomerLabels::INVOICE_STATUSES[$row->status->value] }}</td><td>{{ $row->total_amount }} {{ $row->currency }}</td><td>{{ $row->created_at?->format('Y-m-d H:i') }}</td></tr>
@empty<tr><td colspan="4">暂无符合条件的账单。</td></tr>@endforelse
</tbody></table></div>
