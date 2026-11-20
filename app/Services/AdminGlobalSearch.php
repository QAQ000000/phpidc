<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverType;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Local, bounded projections only; module authorization always precedes its query. */
class AdminGlobalSearch
{
    public function authorize(User $actor): void
    {
        abort_if($actor->trashed() || $actor->anonymized_at !== null, 403);
        abort_unless(Gate::forUser($actor)->any(['customers.view', 'finance.view', 'upstream.view']), 403);
    }

    /** @return list<array{key: string, label: string, items: list<array{id: int, label: string, description: string, url: string}>}> */
    public function search(User $actor, string $term): array
    {
        $this->authorize($actor);
        if ($term === '') {
            return [];
        }
        $gate = Gate::forUser($actor);
        $id = $this->localId($term);
        $groups = [];
        if ($gate->allows('customers.view')) {
            $items = [];
            // Deliberately includes retained, anonymized identities; never resolves old PII.
            if ($id !== null || str_contains($term, '@')) {
                $rows = DB::table('users')->select(['id', 'name', 'email', 'deleted_at', 'anonymized_at'])
                    ->when($id !== null, fn (Builder $query) => $query->where('id', $id), fn (Builder $query) => $query->where('email', $term))
                    ->orderByDesc('id')->limit(5)->get();
                foreach ($rows as $row) {
                    $items[] = ['id' => (int) $row->id, 'label' => $row->name.' · '.$row->email,
                        'description' => '客户 #'.$row->id.($row->deleted_at !== null || $row->anonymized_at !== null ? ' · 已注销' : ''),
                        'url' => '/admin/customers/'.$row->id];
                }
            }
            $groups[] = ['key' => 'customers', 'label' => '客户', 'items' => $items];
        }
        if ($gate->allows('finance.view')) {
            $rows = Invoice::query()->select(['id', 'invoice_no', 'status'])->where(function ($query) use ($id, $term): void {
                $query->where('invoice_no', $term);
                if ($id !== null) {
                    $query->orWhere('id', $id);
                }
            })->orderByDesc('id')->limit(5)->get();
            $items = [];
            foreach ($rows as $row) {
                $items[] = ['id' => $row->id, 'label' => $row->invoice_no,
                    'description' => '账单 #'.$row->id.' · '.$this->statusLabel($row->status->value),
                    'url' => '/admin/finance/invoice?invoice_id='.$row->id];
            }
            $groups[] = ['key' => 'invoices', 'label' => '账单', 'items' => $items];
        }
        if ($gate->allows('upstream.view')) {
            $customerAccess = $gate->allows('customers.view');
            $rows = DB::table('instances')->leftJoin('upstream_apis', 'instances.upstream_api_id', '=', 'upstream_apis.id')
                ->select(['instances.id', 'instances.user_id', 'instances.driver_type', 'instances.display_name',
                    'instances.status', 'instances.upstream_instance_id', 'instances.upstream_api_id', 'upstream_apis.name as connection_name'])
                ->when(! $customerAccess, fn (Builder $query) => $query->where('instances.driver_type', DriverType::ZJMF_API->value))
                ->where(function (Builder $query) use ($id, $term): void {
                    $query->where('instances.upstream_instance_id', $term);
                    if ($id !== null) {
                        $query->orWhere('instances.id', $id);
                    }
                })->orderByDesc('instances.id')->limit(5)->get();
            $items = [];
            foreach ($rows as $row) {
                $driver = DriverType::from($row->driver_type);
                $connection = $row->upstream_api_id === null ? '本地服务' : '连接 #'.$row->upstream_api_id.' '.$row->connection_name;
                $items[] = ['id' => (int) $row->id, 'label' => $row->display_name,
                    'description' => '本地 #'.$row->id.' · '.$connection.($row->upstream_instance_id === null ? '' : ' · 上游 #'.$row->upstream_instance_id).' · '.$this->statusLabel($row->status),
                    'url' => $driver->isUpstreamProxied() ? '/admin/upstream-instances?instance='.$row->id : '/admin/customers/'.$row->user_id.'#customer-services'];
            }
            $groups[] = ['key' => 'instances', 'label' => '服务', 'items' => $items];
        }

        return $groups;
    }

    /** Large digit strings may still be legitimate upstream IDs; never cast them to local IDs. */
    private function localId(string $term): ?string
    {
        if (! ctype_digit($term)) {
            return null;
        }
        $id = ltrim($term, '0');
        $maximum = (string) PHP_INT_MAX;

        return $id !== '' && (strlen($id) < strlen($maximum) || (strlen($id) === strlen($maximum) && strcmp($id, $maximum) <= 0)) ? $id : null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'Unpaid' => '待付款', 'Paid' => '已付款', 'Refunded' => '已退款', 'Expired' => '已过期',
            'Pending' => '待开通', 'Active' => '运行中', 'Suspended' => '已暂停', 'Cancelled' => '已取消',
            'Deleted' => '已删除', 'ProvisionFailed' => '开通失败', 'Fraud' => '风险服务',
            default => $status,
        };
    }
}
