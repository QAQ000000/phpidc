<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class InventoryQueries
{
    /** @return Builder<Product> */
    public function products(): Builder
    {
        return Product::query()->localCardPool()->select(['id', 'name', 'driver_type'])
            ->when(request('q'), fn (Builder $query, $search) => $query->where('name', 'like', '%'.mb_substr((string) $search, 0, 100).'%'))
            ->when(request('product_id'), fn (Builder $query, $id) => $query->whereKey((int) $id))
            ->withCount(['redemptionCodes as available_count' => fn (Builder $q) => $q->where('status', 'available')->whereNull('instance_id')->whereNull('reserved_until'),
                'redemptionCodes as reserved_count' => fn (Builder $q) => $q->where('status', 'reserved'),
                'redemptionCodes as sold_count' => fn (Builder $q) => $q->where('status', 'sold')]);
    }

    /** Safe metadata projection: never fetch task payloads or instance credentials. */
    public function tasks(): QueryBuilder
    {
        return DB::table('fulfillment_tasks as t')->join('instances as i', 'i.id', '=', 't.instance_id')
            ->join('invoices as v', 'v.id', '=', 't.invoice_id')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('v.status', InvoiceStatus::PAID->value)->whereColumn('v.instance_id', 'i.id')
            ->whereIn('i.driver_type', [DriverType::REDEMPTION->value, DriverType::SUB2API->value])
            ->whereNull('i.upstream_api_id')->whereNull('t.upstream_api_id')->whereColumn('i.driver_type', 't.driver_type')
            ->whereNull('i.encrypted_credentials')->whereIn('i.status', [InstanceStatus::PENDING->value, InstanceStatus::PROVISION_FAILED->value])
            ->where('t.action', 'provision')->whereIn('t.status', [FulfillmentTaskStatus::PENDING->value, FulfillmentTaskStatus::RUNNING->value, FulfillmentTaskStatus::FAILED->value])
            ->when(request('product_id'), fn (QueryBuilder $q, $id) => $q->where('p.id', (int) $id))
            ->when(request('q'), fn (QueryBuilder $q, $search) => $q->where('p.name', 'like', '%'.mb_substr((string) $search, 0, 100).'%'))
            ->select(['t.id', 't.status', 't.attempts', 't.max_attempts', 't.available_at', 't.error_message', 'v.invoice_no', 'i.id as instance_id', 'p.id as product_id', 'p.name as product_name'])
            ->selectSub(DB::table('redemption_codes as c')->selectRaw('count(*)')->whereColumn('c.product_id', 'p.id')->where('c.status', 'available')->whereNull('c.instance_id')->whereNull('c.reserved_until'), 'available_count');
    }
}
