<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\User;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** A single local source; navigation never supplies a business target or authority. */
final class AdminWorkspaceNavigation
{
    private const PERMISSIONS = ['customers' => 'customers.view', 'customer' => 'customers.view',
        'instances' => 'upstream.view', 'instance' => 'upstream.view',
        'invoices' => 'finance.view', 'invoice' => 'finance.view'];

    private const CUSTOMER_PAGES = ['services_page', 'invoices_page', 'ledger_page', 'tickets_page',
        'kyc_page', 'notifications_page', 'audit_page'];

    private const INSTANCE_PAGES = ['invoices_page', 'tasks_page', 'tickets_page'];

    private const INVOICE_PAGES = ['attempts_page', 'callbacks_page', 'ledger_page', 'tasks_page'];

    private const TABS = [
        'customer' => [
            'overview' => ['customers.view', []], 'services' => ['upstream.view', ['services_page']],
            'invoices' => ['finance.view', ['invoices_page']], 'ledger' => ['finance.view', ['ledger_page']],
            'tickets' => ['support.view', ['tickets_page']], 'identity' => ['customers.view', ['kyc_page']],
            'notifications' => ['notification.view', ['notifications_page']], 'audit' => ['audit.view', ['audit_page']],
        ],
        'instance' => [
            'overview' => ['upstream.view', []], 'configuration' => ['upstream.view', []],
            'invoices' => ['finance.view', ['invoices_page']], 'tasks' => ['tasks.view', ['tasks_page']],
            'tickets' => ['support.view', ['tickets_page']],
        ],
        'invoice' => [
            'overview' => ['finance.view', []], 'items' => ['finance.view', []],
            'payments' => ['finance.view', ['attempts_page', 'callbacks_page']],
            'ledger' => ['finance.view', ['ledger_page']], 'tasks' => ['tasks.view', ['tasks_page']],
            'recovery' => ['finance.view', []],
        ],
    ];

    private const SECTION_TABS = [
        'customer' => ['customer-profile' => 'overview', 'customer-services' => 'services',
            'customer-invoices' => 'invoices', 'customer-ledger' => 'ledger', 'customer-tickets' => 'tickets',
            'customer-kyc' => 'identity', 'customer-notifications' => 'notifications', 'customer-audit' => 'audit'],
        'instance' => ['instance-summary' => 'overview', 'instance-ownership' => 'overview',
            'instance-configuration' => 'configuration', 'instance-operations' => 'overview',
            'instance-lifecycle' => 'overview', 'instance-hot-swap' => 'overview',
            'instance-invoices' => 'invoices', 'instance-tasks' => 'tasks', 'instance-tickets' => 'tickets'],
        'invoice' => ['invoice-summary' => 'overview', 'invoice-items' => 'items',
            'invoice-payments' => 'payments', 'invoice-ledger' => 'ledger', 'invoice-tasks' => 'tasks',
            'invoice-recovery' => 'recovery'],
    ];

    private const SECTIONS = [
        'customer' => ['customer-profile' => 'customers.view', 'customer-services' => 'upstream.view',
            'customer-invoices' => 'finance.view', 'customer-ledger' => 'finance.view',
            'customer-tickets' => 'support.view', 'customer-kyc' => 'customers.view',
            'customer-notifications' => 'notification.view', 'customer-audit' => 'audit.view'],
        'instance' => ['instance-summary' => 'upstream.view', 'instance-ownership' => 'upstream.view',
            'instance-configuration' => 'upstream.view', 'instance-operations' => 'upstream.view',
            'instance-lifecycle' => 'upstream.view', 'instance-hot-swap' => 'upstream.view',
            'instance-invoices' => 'finance.view', 'instance-tasks' => 'tasks.view', 'instance-tickets' => 'support.view'],
        'invoice' => ['invoice-summary' => 'finance.view', 'invoice-items' => 'finance.view',
            'invoice-payments' => 'finance.view', 'invoice-ledger' => 'finance.view',
            'invoice-tasks' => 'tasks.view', 'invoice-recovery' => 'finance.view'],
    ];

    /** Resolve URL tabs without consulting business tables or accepting arbitrary destinations. */
    public static function tab(string $kind, array $input, ?string $section = null): ?string
    {
        $tabs = self::TABS[$kind] ?? [];
        if ($tabs === []) {
            return null;
        }
        if (array_key_exists('tab', $input)) {
            return is_string($input['tab']) && isset($tabs[$input['tab']]) ? $input['tab'] : null;
        }
        foreach ($tabs as $tab => [, $pages]) {
            foreach ($pages as $page) {
                if (isset($input[$page])) {
                    return $tab;
                }
            }
        }

        return self::SECTION_TABS[$kind][$section ?? ''] ?? 'overview';
    }

    public static function query(User $actor, string $kind, array $input): array
    {
        $query = self::ownQuery($kind, $input) ?? [];
        $source = self::source($input['from'] ?? null, $actor);
        if ($source !== null) {
            $query['from'] = $source;
        }

        return $query;
    }

    public static function origin(User $actor, string $kind, ?int $id, array $input, ?string $section = null): array
    {
        $source = self::source(['kind' => $kind, 'id' => $id,
            'query' => $input, 'section' => $section], $actor);

        return $source === null ? [] : ['from' => $source];
    }

    public static function url(string $kind, ?int $id = null, array $query = [], ?string $section = null): string
    {
        $path = match ($kind) {
            'customers' => '/admin/customers', 'instances' => '/admin/upstream-instances', 'invoices' => '/admin/finance',
            'customer' => '/admin/customers/'.self::objectId($id),
            'instance' => '/admin/instances/'.self::objectId($id), 'invoice' => '/admin/finance/invoice',
            default => throw new InvalidArgumentException('未知工作台类型。'),
        };
        $clean = self::ownQuery($kind, $query, $section) ?? [];
        if ($kind === 'instances' && ($selected = self::positiveInteger($query['instance'] ?? null, PHP_INT_MAX)) !== null) {
            $clean['instance'] = $selected;
        }
        if ($kind === 'invoice') {
            $clean = ['invoice_id' => self::objectId($id)] + $clean;
        }
        $source = self::source($query['from'] ?? null);
        if ($source !== null) {
            $clean['from'] = $source;
        }
        $section = is_string($section) && (isset(self::SECTIONS[$kind][$section])
            || ($kind === 'instances' && in_array($section, ['instance-lifecycle', 'instance-hot-swap'], true))) ? $section : null;
        if ($section !== null && isset($clean['tab']) && (self::SECTION_TABS[$kind][$section] ?? null) !== $clean['tab']) {
            $section = null;
        }

        return $path.($clean === [] ? '' : '?'.http_build_query($clean)).($section === null ? '' : '#'.$section);
    }

    /** @return array{url:string,label:string} */
    public static function returnLink(User $actor, array $input, string $fallbackUrl, string $fallbackLabel): array
    {
        $source = self::source($input['from'] ?? null, $actor);
        if ($source === null) {
            return ['url' => $fallbackUrl, 'label' => $fallbackLabel];
        }
        $label = match ($source['kind']) {
            'customers' => '返回客户列表', 'customer' => '返回客户 #'.$source['id'],
            'instances' => '返回经典服务列表', 'instance' => '返回实例 #'.$source['id'],
            'invoices' => '返回账单列表', 'invoice' => '返回账单 #'.$source['id'],
            default => throw new InvalidArgumentException('未知工作台类型。'),
        };

        return ['url' => self::url($source['kind'], $source['id'] ?? null, $source['query'], $source['section'] ?? null), 'label' => $label];
    }

    private static function source(mixed $input, ?User $actor = null): ?array
    {
        if (! is_array($input) || ! is_string($kind = $input['kind'] ?? null) || ! isset(self::PERMISSIONS[$kind])
            || ($actor !== null && (! $actor->isAccessEnabled() || ! $actor->can(self::PERMISSIONS[$kind])))) {
            return null;
        }
        $id = null;
        if (in_array($kind, ['customer', 'instance', 'invoice'], true)) {
            $id = self::positiveInteger($input['id'] ?? null, PHP_INT_MAX);
            if ($id === null) {
                return null;
            }
        }
        if (! is_array($input['query'] ?? []) || (isset($input['section']) && ! is_string($input['section']))) {
            return null;
        }
        $section = $input['section'] ?? null;
        $ability = is_string($section) ? (self::SECTIONS[$kind][$section] ?? null) : null;
        $section = $ability !== null && ($actor === null || $actor->can($ability)) ? $section : null;
        $query = self::ownQuery($kind, $input['query'] ?? [], $section);
        if ($query === null) {
            return null;
        }
        if ($actor !== null && isset($query['tab']) && ! $actor->can(self::TABS[$kind][$query['tab']][0])) {
            return null;
        }
        $source = ['kind' => $kind];
        if ($id !== null) {
            $source['id'] = $id;
        }
        $source['query'] = $query;
        if ($section !== null) {
            $source['section'] = $section;
        }

        return $source;
    }

    private static function ownQuery(string $kind, array $input, ?string $section = null): ?array
    {
        $integer = ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX];
        $page = ['nullable', 'integer', 'min:1', 'max:2147483647'];
        $invoice = ['invoice_no' => ['nullable', 'string', 'max:64'], 'user_id' => $integer,
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)], 'type' => ['nullable', Rule::enum(InvoiceType::class)], 'page' => $page];
        $rules = match ($kind) {
            'customers' => ['user_id' => $integer, 'email' => ['nullable', 'string', 'email', 'max:255'],
                'name' => ['nullable', 'string', 'max:100'], 'kyc' => ['nullable', Rule::in(['verified', 'unverified'])],
                'status' => ['nullable', Rule::in(['active', 'disabled', 'closed'])], 'page' => $page],
            'instances' => ['q' => ['nullable', 'string', 'max:100'], 'account_id' => $integer, 'user_id' => $integer,
                'status' => ['nullable', Rule::enum(InstanceStatus::class)], 'page' => $page],
            'invoices' => $invoice,
            'customer' => array_fill_keys(self::CUSTOMER_PAGES, $page),
            'instance' => array_fill_keys(self::INSTANCE_PAGES, $page),
            'invoice' => $invoice + array_fill_keys(self::INVOICE_PAGES, $page),
            default => [],
        };
        $validator = validator($input, $rules);
        if ($validator->fails()) {
            return null;
        }
        $tab = isset(self::TABS[$kind]) ? self::tab($kind, $input, $section) : null;
        if (isset(self::TABS[$kind]) && $tab === null) {
            return null;
        }
        $query = [];
        foreach ($validator->validated() as $key => $value) {
            if ($value === null || $value === '' || (($key === 'page' || str_ends_with($key, '_page')) && (int) $value === 1)) {
                continue;
            }
            if ($tab !== null && str_ends_with($key, '_page') && ! in_array($key, self::TABS[$kind][$tab][1], true)) {
                continue;
            }
            $query[$key] = $value;
        }
        if ($tab !== null && (array_key_exists('tab', $input) || $section !== null
            || array_intersect(array_keys($input), [...self::CUSTOMER_PAGES, ...self::INSTANCE_PAGES, ...self::INVOICE_PAGES]) !== [])) {
            $query['tab'] = $tab;
        }

        return $query;
    }

    private static function positiveInteger(mixed $value, int $maximum): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);

        return $parsed === false ? null : $parsed;
    }

    private static function objectId(?int $id): int
    {
        if ($id === null || $id < 1) {
            throw new InvalidArgumentException('工作台对象编号无效。');
        }

        return $id;
    }
}
