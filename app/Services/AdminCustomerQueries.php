<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstanceStatus;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\KycVerification;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AdminAuditProjection;
use App\Support\AdminCustomerAccess;
use App\Support\AdminFinancialAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Local, bounded projections only; no identity or financial business actions. */
class AdminCustomerQueries
{
    private const BASIC_COLUMNS = ['id', 'name', 'email', 'phone', 'email_verified_at', 'kyc_verified_at', 'disabled_at', 'anonymized_at', 'deleted_at', 'created_at'];

    private const WALLET_COLUMNS = ['credit', 'frozen_credit', 'wallet_clearance_frozen'];

    private const DETAIL_PAGES = ['services_page', 'invoices_page', 'ledger_page', 'tickets_page', 'kyc_page', 'notifications_page', 'audit_page'];

    public function listing(User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('customers.view');
        $validator = validator($input, [
            'user_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'kyc' => ['nullable', Rule::in(['verified', 'unverified'])],
            'status' => ['nullable', Rule::in(['active', 'disabled', 'closed'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ]);
        abort_if($validator->fails(), 422, '客户筛选或分页参数无效。');
        $filters = array_replace(['user_id' => '', 'email' => '', 'name' => '', 'kyc' => '', 'status' => 'active'],
            array_filter($validator->safe()->except('page'), fn (mixed $value): bool => $value !== null && $value !== ''));
        $permissions = $this->permissions($actor);
        $query = $this->customers($permissions['finance'], $permissions['services']);
        if ($filters['status'] === 'closed') {
            $query->where(fn (Builder $closed) => $closed->whereNotNull('deleted_at')->orWhereNotNull('anonymized_at'));
        } else {
            $query->whereNull('deleted_at')->whereNull('anonymized_at');
            $filters['status'] === 'disabled' ? $query->whereNotNull('disabled_at') : $query->whereNull('disabled_at');
        }
        if ($filters['user_id'] !== '') {
            $query->whereKey((int) $filters['user_id']);
        }
        if ($filters['email'] !== '') {
            $query->where('email', $filters['email']);
        }
        if ($filters['name'] !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['name']);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        if ($filters['kyc'] !== '') {
            $filters['kyc'] === 'verified' ? $query->whereNotNull('kyc_verified_at') : $query->whereNull('kyc_verified_at');
        }

        return ['customers' => $query->latest('id')->paginate(20, ['*'], 'page', (int) ($input['page'] ?? 1))->appends($filters),
            'filters' => $filters, 'permissions' => $permissions];
    }

    public function detail(User $actor, int $customerId, array $input): array
    {
        Gate::forUser($actor)->authorize('customers.view');
        $validator = validator($input, array_fill_keys(self::DETAIL_PAGES, ['nullable', 'integer', 'min:1', 'max:2147483647']));
        abort_if($validator->fails(), 422, '客户明细分页参数无效。');
        $pages = $validator->validated();
        $permissions = $this->permissions($actor);
        $customer = $this->customers($permissions['finance'], $permissions['services'])->findOrFail($customerId);
        $management = ['profile' => $actor->can('customers.manage'), 'password_reset' => $actor->can('customers.password-reset'), 'access' => $actor->can('customers.status.manage')];
        if (in_array(true, $management, true) && app(AdminCustomerAccess::class)->isManageableTarget($actor, $customer)) {
            $management['password_reset'] = $management['password_reset'] && $customer->disabled_at === null;
        } else {
            $management = array_fill_keys(array_keys($management), false);
        }
        $data = ['customer' => $customer, 'permissions' => $permissions, 'management' => $management,
            'walletAdjustment' => $permissions['finance'] && $actor->can('finance.wallet-adjust')
                && app(AdminFinancialAccess::class)->isManageableTarget($actor, $customer),
            'kycRecords' => KycVerification::query()->where('user_id', $customer->id)
                ->latest('id')->paginate(10, ['id', 'driver', 'status', 'masked_summary', 'verified_at', 'created_at'], 'kyc_page', (int) ($pages['kyc_page'] ?? 1))->appends($pages)->fragment('customer-kyc')];
        if ($permissions['services']) {
            $data['services'] = Instance::query()->where('user_id', $customer->id)
                ->with('upstreamApi:id,name')->latest('id')
                ->paginate(10, ['id', 'user_id', 'display_name', 'driver_type', 'status', 'hostname', 'primary_ip', 'next_due_at', 'upstream_api_id', 'upstream_instance_id'], 'services_page', (int) ($pages['services_page'] ?? 1))->appends($pages)->fragment('customer-services');
        }
        if ($permissions['finance']) {
            $data['invoices'] = Invoice::query()->where('user_id', $customer->id)->latest('id')
                ->paginate(10, ['id', 'invoice_no', 'status', 'type', 'currency', 'total_amount', 'paid_at', 'created_at'], 'invoices_page', (int) ($pages['invoices_page'] ?? 1))->appends($pages)->fragment('customer-invoices');
            $data['ledger'] = FinancialTransaction::query()->where('user_id', $customer->id)->latest('id')
                ->paginate(10, ['id', 'invoice_id', 'type', 'amount', 'balance_after', 'frozen_after', 'created_at'], 'ledger_page', (int) ($pages['ledger_page'] ?? 1))->appends($pages)->fragment('customer-ledger');
        }
        if ($permissions['support']) {
            $data['tickets'] = Ticket::query()->where('user_id', $customer->id)->latest('id')
                ->paginate(10, ['id', 'instance_id', 'subject', 'status', 'created_at', 'updated_at'], 'tickets_page', (int) ($pages['tickets_page'] ?? 1))->appends($pages)->fragment('customer-tickets');
        }
        if ($permissions['notifications']) {
            $data['notifications'] = DB::table('notification_logs')->where('user_id', $customer->id)->latest('id')
                ->paginate(10, ['id', 'event_key', 'channel', 'status', 'created_at'], 'notifications_page', (int) ($pages['notifications_page'] ?? 1))->appends($pages)->fragment('customer-notifications');
        }
        if ($permissions['audit']) {
            $data['audits'] = DB::table('audit_logs')->where('subject_type', User::class)->where('subject_id', $customer->id)->latest('id')
                ->paginate(10, ['id', 'actor_type', 'actor_id', 'action', 'before', 'after', 'created_at'], 'audit_page', (int) ($pages['audit_page'] ?? 1))->appends($pages)->fragment('customer-audit')
                ->through(function (object $record): object {
                    $record->before = AdminAuditProjection::json($record->before);
                    $record->after = AdminAuditProjection::json($record->after);

                    return $record;
                });
        }

        return $data;
    }

    /** @return Builder<User> */
    private function customers(bool $finance, bool $services): Builder
    {
        $query = User::withTrashed()->select(self::BASIC_COLUMNS);
        if ($finance) {
            $query->addSelect(self::WALLET_COLUMNS);
        }
        if ($services) {
            $query->selectSub(Instance::query()->selectRaw('COUNT(*)')->whereColumn('user_id', 'users.id')
                ->whereIn('status', [InstanceStatus::ACTIVE, InstanceStatus::SUSPENDED]), 'active_services_count');
        }

        return $query;
    }

    /** @return array<string, bool> */
    private function permissions(User $actor): array
    {
        return ['finance' => $actor->can('finance.view'), 'services' => $actor->can('upstream.view'),
            'support' => $actor->can('support.view'), 'notifications' => $actor->can('notification.view'),
            'audit' => $actor->can('audit.view'), 'access' => $actor->can('access.view')];
    }
}
