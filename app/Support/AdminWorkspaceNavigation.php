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

    private const SECTIONS = [
        'customer' => ['customer-profile' => 'customers.view', 'customer-services' => 'upstream.view',
            'customer-invoices' => 'finance.view', 'customer-ledger' => 'finance.view',
            'customer-tickets' => 'support.view', 'customer-kyc' => 'customers.view',
            'customer-notifications' => 'notification.view', 'customer-audit' => 'audit.view'],
        'instance' => ['instance-summary' => 'upstream.view', 'instance-ownership' => 'upstream.view',
            'instance-configuration' => 'upstream.view', 'instance-operations' => 'upstream.view',
            'instance-invoices' => 'finance.view', 'instance-tasks' => 'tasks.view', 'instance-tickets' => 'support.view'],
        'invoice' => ['invoice-summary' => 'finance.view', 'invoice-tasks' => 'tasks.view', 'invoice-recovery' => 'finance.view'],
    ];

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
            'query' => self::ownQuery($kind, $input) ?? [], 'section' => $section], $actor);

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
        $clean = self::ownQuery($kind, $query) ?? [];
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
        $query = self::ownQuery($kind, $input['query'] ?? []);
        if ($query === null) {
            return null;
        }
        $source = ['kind' => $kind];
        if ($id !== null) {
            $source['id'] = $id;
        }
        $source['query'] = $query;
        $section = $input['section'] ?? null;
        $ability = is_string($section) ? (self::SECTIONS[$kind][$section] ?? null) : null;
        if ($ability !== null && ($actor === null || $actor->can($ability))) {
            $source['section'] = $section;
        }

        return $source;
    }

    private static function ownQuery(string $kind, array $input): ?array
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
        $query = [];
        foreach ($validator->validated() as $key => $value) {
            if ($value === null || $value === '' || (($key === 'page' || str_ends_with($key, '_page')) && (int) $value === 1)) {
                continue;
            }
            $query[$key] = $value;
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
