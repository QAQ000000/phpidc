<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class DescribeInstanceOrderFieldsAction
{
    /** @return array{fields: list<array{label: string, value: string}>, unavailable: bool} */
    public function execute(User $user, Instance $instance): array
    {
        abort_unless($instance->user_id === $user->id, 403);
        $empty = ['fields' => [], 'unavailable' => false];
        $product = $instance->product;
        $mappedPid = $instance->active_config['upstream_pid'] ?? null;
        if ($product === null || $instance->upstream_api_id === null || $product->upstream_pid === null
            || $product->driver_type !== $instance->driver_type || $product->upstream_api_id !== $instance->upstream_api_id
            || (! is_string($mappedPid) && ! is_int($mappedPid)) || (string) $product->upstream_pid !== (string) $mappedPid) {
            return $empty;
        }

        $definitions = $product->customFields()->where('sync_source', 'upstream')->where('is_hidden', false)
            ->where('show_on_detail', true)->whereIn('field_type', ['text', 'textarea', 'select', 'password'])
            ->orderBy('id')->get(['upstream_field_id', 'name', 'field_type']);
        if ($definitions->isEmpty() || $instance->bindings()->whereNotNull('unbound_at')->exists()) {
            // A replacement resource must not inherit the original host's order fields.
            return $empty;
        }

        $invoice = Invoice::where('user_id', $user->id)->where('instance_id', $instance->id)->where('product_id', $product->id)
            ->where('type', InvoiceType::NEW_ORDER)->whereIn('status', [InvoiceStatus::PAID, InvoiceStatus::REFUNDED])
            ->oldest('id')->first(['line_items']);
        $lineItems = $invoice?->line_items;
        $purchase = is_array($lineItems) ? ($lineItems['purchase'] ?? null) : null;
        $purchasePid = is_array($purchase) ? ($purchase['upstream_pid'] ?? null) : null;
        if (! is_array($purchase) || ($purchase['driver_type'] ?? null) !== $instance->driver_type->value
            || ($purchase['upstream_api_id'] ?? null) !== $instance->upstream_api_id
            || (! is_string($purchasePid) && ! is_int($purchasePid)) || (string) $purchasePid !== (string) $product->upstream_pid) {
            return $empty;
        }

        $unavailable = ['fields' => [], 'unavailable' => true];
        if (! is_string($purchase['encrypted_params'] ?? null)) {
            return $unavailable;
        }
        try {
            $params = json_decode(Crypt::decryptString($purchase['encrypted_params']), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // Report unreadable historical display data without exposing the private parameters.
            return $unavailable;
        }
        if (! is_array($params) || ! is_array($params['options'] ?? null) || ! is_array($params['options']['customfield'] ?? null)) {
            return $unavailable;
        }

        $values = $params['options']['customfield'];
        $fields = [];
        foreach ($definitions as $field) {
            if ($field->upstream_field_id === null || ! array_key_exists($field->upstream_field_id, $values)) {
                continue;
            }
            $value = $values[$field->upstream_field_id];
            if (! is_string($value) || strlen($value) > 4096) {
                return $unavailable;
            }
            $fields[] = ['label' => $field->name, 'value' => $value === '' ? '未填写'
                : ($field->field_type === 'password' ? '已填写（内容已隐藏）' : $value)];
        }

        return ['fields' => $fields, 'unavailable' => false];
    }
}
