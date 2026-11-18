<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\BillingCycle;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PurchaseRequest
{
    public function id(array $input): string
    {
        $id = $input['request_id'] ?? null;
        if (! is_string($id) || ! Str::isUlid($id)) {
            throw ValidationException::withMessages(['request_id' => 'A stable purchase request ULID is required.']);
        }

        return strtoupper($id);
    }

    /** Canonical caller intent, before quotation, option resolution or generated credentials. */
    public function snapshot(int $productId, BillingCycle $cycle, array $options, bool $useCredit, ?string $gateway, array $input): string
    {
        if (array_diff(array_keys($options), ['configoption', 'customfield']) !== []) {
            $this->invalidOptions();
        }
        $configuration = $this->map($options['configoption'] ?? [], true);
        $custom = $this->map($options['customfield'] ?? [], false);

        return json_encode(['version' => 1, 'product_id' => $productId, 'billing_cycle' => $cycle->value,
            'options' => ['configoption' => $configuration, 'customfield' => $custom],
            'hostname' => $this->text($input, 'hostname'), 'password' => $this->text($input, 'password'),
            'coupon_code' => $this->text($input, 'coupon_code'), 'use_credit' => $useCredit,
            'gateway' => $gateway === '' ? null : $gateway], JSON_THROW_ON_ERROR);
    }

    private function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw ValidationException::withMessages([$key => 'A string value is required.']);
        }

        return $value === '' ? null : $value;
    }

    private function map(mixed $values, bool $numeric): array
    {
        if (! is_array($values)) {
            $this->invalidOptions();
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! preg_match('/^[1-9][0-9]*$/D', (string) $key)) {
                $this->invalidOptions();
            }
            if ($numeric) {
                if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
                    $this->invalidOptions();
                }
                $result[$key] = (string) (int) $value;
            } else {
                if ($value !== null && ! is_string($value)) {
                    $this->invalidOptions();
                }
                $result[$key] = $value ?? '';
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    private function invalidOptions(): never
    {
        throw ValidationException::withMessages(['options' => 'Unsupported purchase configuration structure.']);
    }
}
