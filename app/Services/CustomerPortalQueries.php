<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class CustomerPortalQueries
{
    private const INVOICE_COLUMNS = ['id', 'user_id', 'product_id', 'invoice_no', 'status', 'type', 'total_amount', 'currency', 'expires_at', 'created_at'];

    private const INSTANCE_COLUMNS = ['id', 'user_id', 'product_id', 'display_name', 'status', 'billing_cycle', 'recurring_amount', 'currency', 'next_due_at', 'primary_ip', 'created_at'];

    public function dashboard(User $user): array
    {
        return ['invoices' => $this->invoices($user)->latest('id')->limit(10)->get(self::INVOICE_COLUMNS),
            'instances' => $this->instances($user)->latest('id')->limit(10)->get(self::INSTANCE_COLUMNS),
            'invoiceCount' => $this->invoices($user)->count(), 'unpaidCount' => $this->invoices($user)->where('status', InvoiceStatus::UNPAID)->count(),
            'instanceCount' => $this->instances($user)->count(), 'activeCount' => $this->instances($user)->where('status', InstanceStatus::ACTIVE)->count()];
    }

    public function invoiceList(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->invoices($user);
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['q'] ?? '') !== '') {
            $term = $this->term($filters['q']);
            $query->where(fn (Builder $q) => $q->whereRaw("invoice_no LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('product', fn (Builder $product) => $product->whereRaw("name LIKE ? ESCAPE '!'", [$term])));
        }

        return $query->latest('id')->paginate(20, self::INVOICE_COLUMNS)->withQueryString();
    }

    public function instanceList(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->instances($user);
        if (($filters['status'] ?? '') !== '') {
            $query->where('status', $filters['status']);
        }
        if (($filters['q'] ?? '') !== '') {
            $term = $this->term($filters['q']);
            $query->where(fn (Builder $q) => $q->whereRaw("display_name LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("primary_ip LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('product', fn (Builder $product) => $product->whereRaw("name LIKE ? ESCAPE '!'", [$term])));
        }

        return $query->latest('id')->paginate(20, self::INSTANCE_COLUMNS)->withQueryString();
    }

    public function invoiceDetails(User $user, Invoice $invoice): array
    {
        abort_unless($invoice->user_id === $user->id, 403);
        $invoice->loadMissing('product:id,name');
        $items = array_values(array_filter($invoice->line_items['items'] ?? [], 'is_array'));

        return ['invoice' => $invoice, 'items' => array_map(fn (array $item) => Arr::only($item, ['kind', 'label', 'qty', 'unit_price', 'amount', 'cycle']), $items),
            'linkedInstance' => $invoice->instance_id === null ? null : $this->instances($user)->whereKey($invoice->instance_id)->first(self::INSTANCE_COLUMNS)];
    }

    public function instanceDetails(User $user, Instance $instance): array
    {
        abort_unless($instance->user_id === $user->id, 403);
        $instance->loadMissing('product');

        return ['instance' => $instance, 'invoices' => $this->invoices($user)->where('instance_id', $instance->id)
            ->latest('id')->paginate(10, self::INVOICE_COLUMNS)->withQueryString()];
    }

    /** @return Builder<Invoice> */
    private function invoices(User $user): Builder
    {
        return Invoice::where('user_id', $user->id)->with('product:id,name');
    }

    /** @return Builder<Instance> */
    private function instances(User $user): Builder
    {
        return Instance::where('user_id', $user->id)->with('product:id,name');
    }

    private function term(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
