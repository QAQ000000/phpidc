<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Ticket;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\AdminWorkspaceNavigation;
use App\Support\CustomerLabels;
use Illuminate\Support\Facades\Gate;

/** Bounded local projections; never load delivery credentials or invoke a driver. */
class AdminInstanceQueries
{
    public function detail(User $actor, int $instanceId, array $input): array
    {
        abort_unless($actor->isAccessEnabled(), 403);
        Gate::forUser($actor)->authorize('upstream.view');
        $validator = validator($input, array_fill_keys(['invoices_page', 'tasks_page', 'tickets_page'],
            ['nullable', 'integer', 'min:1', 'max:2147483647']));
        abort_if($validator->fails(), 422, '实例明细分页参数无效。');
        $pages = $validator->validated() + AdminWorkspaceNavigation::query($actor, 'instance', $input);
        $permissions = ['customers' => $actor->can('customers.view'), 'finance' => $actor->can('finance.view'),
            'tasks' => $actor->can('tasks.view'), 'support' => $actor->can('support.view')];
        $instance = Instance::query()->select(['id', 'user_id', 'product_id', 'driver_type', 'upstream_api_id',
            'upstream_instance_id', 'display_name', 'hostname', 'primary_ip', 'assigned_ips', 'status', 'billing_cycle',
            'currency', 'recurring_amount', 'is_price_locked', 'next_due_at', 'last_synced_at', 'created_at', 'updated_at',
            'active_config->upstream_configoption as public_option_selection',
            'active_config->mapping_requires_review as mapping_review', 'active_config->price_requires_review as price_review',
            'active_config->cancellation->task_id as cancellation_task', 'active_config->cloud_operation->status as machine_operation_status'])
            ->findOrFail($instanceId);
        $configuration = $this->publicOptions($instance);
        $mappingReview = $this->truth($instance->getAttribute('mapping_review'));
        $priceReview = $this->truth($instance->getAttribute('price_review'));
        $cancellation = $instance->getAttribute('cancellation_task') !== null;
        $operation = $instance->getAttribute('machine_operation_status');
        $machinePending = in_array($operation, ['requested', 'unknown'], true);
        foreach (['public_option_selection', 'mapping_review', 'price_review', 'cancellation_task', 'machine_operation_status'] as $attribute) {
            $instance->offsetUnset($attribute);
        }
        $classic = $instance->driver_type === DriverType::ZJMF_API;
        $connection = $instance->upstream_api_id === null ? null : UpstreamApi::query()
            ->find($instance->upstream_api_id, ['id', 'name', 'is_active', 'driver']);
        $owner = $permissions['customers'] ? User::withTrashed()->find($instance->user_id,
            ['id', 'name', 'disabled_at', 'deleted_at', 'anonymized_at']) : null;
        $closedOwner = $owner !== null && ($owner->deleted_at !== null || $owner->anonymized_at !== null);
        $bound = $classic && $connection?->is_active && $connection->driver === DriverType::ZJMF_API
            && is_string($instance->upstream_instance_id) && $instance->upstream_instance_id !== '';
        $data = ['instance' => $instance, 'permissions' => $permissions, 'classic' => $classic,
            'connection' => $connection, 'owner' => $owner,
            'product' => Product::query()->find($instance->product_id, ['id', 'name']),
            'configuration' => $configuration, 'mappingReview' => $mappingReview, 'priceReview' => $priceReview,
            'cancellationPending' => $cancellation, 'machinePending' => $machinePending,
            'canMachine' => $bound && ! $closedOwner && ! $cancellation
                && $instance->status === InstanceStatus::ACTIVE && $actor->can('upstream.machine-control'),
            'canSync' => $bound && ! $closedOwner && ! $instance->status->isTerminal() && $actor->can('upstream.sync'),
            'canLifecycle' => $classic && $actor->can('upstream.lifecycle'),
            'canHotSwap' => $classic && $actor->can('upstream.hotswap'),
            'canMapping' => $classic && $actor->can('upstream.mapping-review'),
            'assignedIps' => array_values(array_slice(array_filter(is_array($instance->assigned_ips) ? $instance->assigned_ips : [],
                fn (mixed $ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false), 0, 100)),
            'instanceLabels' => CustomerLabels::INSTANCE_STATUSES, 'invoiceLabels' => CustomerLabels::INVOICE_STATUSES,
            'invoiceTypes' => CustomerLabels::INVOICE_TYPES,
            'driverLabel' => match ($instance->driver_type) {
                DriverType::ZJMF_API => '经典魔方', DriverType::REDEMPTION => '本地卡密',
                DriverType::SUB2API => 'Sub2API', DriverType::PROXMOX => 'Proxmox', DriverType::KYC_ZJMF_LEGACY => '历史驱动',
            },
            'taskLabels' => ['pending' => '待执行', 'running' => '执行中', 'success' => '已完成', 'failed' => '失败', 'stale' => '已失效'],
            'actionLabels' => ['provision' => '开通', 'renew' => '续费', 'upgrade' => '升级', 'suspend' => '暂停',
                'unsuspend' => '恢复', 'terminate' => '终止', 'register_callback' => '登记回调'],
            'ticketLabels' => ['open' => '待回复', 'answered' => '已回复', 'closed' => '已关闭'],
            'invoices' => null, 'tasks' => null, 'tickets' => null, 'retryTasks' => []];
        if ($permissions['finance']) {
            $data['invoices'] = Invoice::query()->where('instance_id', $instance->id)->where('user_id', $instance->user_id)
                ->latest('id')->paginate(10, ['id', 'invoice_no', 'type', 'status', 'currency', 'total_amount', 'paid_at', 'created_at'],
                    'invoices_page', (int) ($pages['invoices_page'] ?? 1))->appends($pages)->fragment('instance-invoices');
        }
        if ($permissions['tasks']) {
            $tasks = FulfillmentTask::query()->where('instance_id', $instance->id)->where('user_id', $instance->user_id)
                ->latest('id')->paginate(10, ['id', 'user_id', 'invoice_id', 'instance_id', 'driver_type', 'upstream_api_id', 'action',
                    'status', 'attempts', 'max_attempts', 'available_at', 'executed_at', 'created_at'],
                    'tasks_page', (int) ($pages['tasks_page'] ?? 1))->appends($pages)->fragment('instance-tasks');
            $data['tasks'] = $tasks;
            if ($permissions['finance'] && ! $closedOwner && ! $instance->status->isTerminal()) {
                $paid = Invoice::query()->whereKey($tasks->pluck('invoice_id')->filter())->where('instance_id', $instance->id)
                    ->where('user_id', $instance->user_id)->where('status', InvoiceStatus::PAID)->pluck('id')->all();
                $data['retryTasks'] = $tasks->filter(fn (FulfillmentTask $task): bool => $task->status === FulfillmentTaskStatus::FAILED
                    && in_array($task->invoice_id, $paid, true) && $task->driver_type === $instance->driver_type
                    && $task->upstream_api_id === $instance->upstream_api_id
                    && ($classic ? $actor->can('upstream.lifecycle')
                        : $task->upstream_api_id === null && in_array($instance->driver_type, [DriverType::REDEMPTION, DriverType::SUB2API], true)
                            && $task->action === 'provision' && in_array($instance->status, [InstanceStatus::PENDING, InstanceStatus::PROVISION_FAILED], true)
                            && $actor->can('inventory.view') && $actor->can('inventory.retry')))->pluck('id')->all();
            }
        }
        if ($permissions['support']) {
            $data['tickets'] = Ticket::query()->where('instance_id', $instance->id)->where('user_id', $instance->user_id)
                ->latest('id')->paginate(10, ['id', 'subject', 'status', 'created_at', 'updated_at'],
                    'tickets_page', (int) ($pages['tickets_page'] ?? 1))->appends($pages)->fragment('instance-tickets');
        }

        return $data;
    }

    private function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    /** Resolve only published local option names from the saved map of numeric upstream IDs. */
    private function publicOptions(Instance $instance): array
    {
        if ($instance->driver_type !== DriverType::ZJMF_API) {
            return [];
        }
        $raw = $instance->getAttribute('public_option_selection');
        $selection = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($selection) || $selection === []) {
            return [];
        }
        $facts = [];
        $groups = ProductOptionGroup::query()->where('product_id', $instance->product_id)->where('sync_source', 'upstream')
            ->where('is_hidden', false)->orderBy('sort_order')->orderBy('id')->limit(100)
            ->with(['items' => fn ($items) => $items->where('is_hidden', false)->select(['id', 'option_group_id', 'label', 'upstream_sub_id'])])
            ->get(['id', 'name', 'type', 'upstream_group_id']);
        foreach ($groups as $group) {
            $value = $selection[$group->upstream_group_id] ?? null;
            if (filter_var($value, FILTER_VALIDATE_INT) === false || $value === null || is_bool($value) || (int) $value < 0) {
                continue;
            }
            $item = $group->items->firstWhere('upstream_sub_id', $value);
            if (in_array($group->type, ['quantity', 'slider'], true)) {
                $display = (string) $value;
            } elseif ($group->type === 'toggle' && (int) $value === 0) {
                $display = '否';
            } elseif ($item !== null) {
                $display = mb_substr($item->label, 0, 255);
            } else {
                continue;
            }
            $facts[] = ['label' => mb_substr($group->name, 0, 255), 'value' => $display];
        }

        return $facts;
    }
}
