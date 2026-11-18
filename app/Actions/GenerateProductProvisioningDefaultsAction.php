<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use DomainException;

class GenerateProductProvisioningDefaultsAction
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    private const NUMBERS = '0123456789';

    private const SPECIAL = '~@#$&*(){}[]|';

    /** @return array{hostname: ?string, password: ?string, errors: array<string, string>} */
    public function execute(Product $product): array
    {
        $rules = $product->provisioning_rules ?? [];
        $result = ['hostname' => null, 'password' => null, 'errors' => []];
        foreach (['hostname', 'password'] as $field) {
            try {
                $result[$field] = $field === 'hostname' ? $this->hostname($rules) : $this->password($rules);
            } catch (DomainException $exception) {
                $result['errors'][$field] = $exception->getMessage();
            }
        }

        return $result;
    }

    private function hostname(array $rules): string
    {
        $remote = $this->snapshot($rules, 'host');
        $rule = $this->remoteRule($remote, '主机名');
        $localPrefix = $rules['hostname_prefix'] ?? '';
        $prefix = $remote === null ? ($localPrefix !== '' ? $localPrefix : 'ser') : (($remote['show'] ?? false) ? ($remote['prefix'] ?? '') : 'ser');
        if (! is_string($prefix) || ! is_string($localPrefix)) {
            throw new DomainException('主机名前缀配置不可读，请手动填写或联系管理员。');
        }
        $remotePrefix = $prefix;
        if (str_starts_with($localPrefix, $prefix)) {
            $prefix = $localPrefix;
        } elseif (! str_starts_with($prefix, $localPrefix)) {
            throw new DomainException('本地与上游主机名前缀冲突，无法自动生成，请联系管理员。');
        }
        [$pool, $required] = $this->characterRules($rule, false);
        if ($rule !== []) {
            $suffixLength = $this->configuredLength($rule['len_num'] ?? null, '主机名长度');
            $totalLength = strlen($remotePrefix) + $suffixLength;
            if ($suffixLength < 1 || $totalLength > 253 || $totalLength < strlen($prefix)) {
                throw new DomainException('主机名长度与前缀配置不兼容，无法自动生成，请手动填写。');
            }
            $lengths = [$totalLength];
        } else {
            if (strlen($prefix) > 252) {
                throw new DomainException('主机名前缀过长，无法自动生成，请联系管理员。');
            }
            $minimum = strlen($prefix) + 1;
            $lengths = array_values(array_unique([min(253, strlen($prefix) + 12), ...range($minimum, max(64, $minimum))]));
        }
        $pools = $pool === '' ? [self::LOWER.self::NUMBERS, self::UPPER.self::LOWER.self::NUMBERS, self::NUMBERS] : [$pool];

        return $this->search($prefix, $lengths, $pools, $required, $rules['hostname_regex'] ?? null, '主机名');
    }

    private function password(array $rules): string
    {
        $remote = $this->snapshot($rules, 'password');
        $rule = $this->remoteRule($remote, '密码');
        $minimum = max(1, $this->integer($rules['password_min_len'] ?? 1, '密码最短长度'));
        $maximum = $this->integer($rules['password_max_len'] ?? 128, '密码最长长度');
        $preferred = 16;
        if ($rule !== []) {
            $minimum = max(6, $minimum);
            $maximum = min(20, $maximum);
            $preferred = max(6, min(20, $this->configuredLength($rule['len_num'] ?? null, '密码默认长度')));
        }
        if ($minimum > $maximum || $minimum > 128) {
            throw new DomainException('本地与上游密码长度不兼容或超出自动生成范围，请手动填写或联系管理员。');
        }
        $maximum = min(128, $maximum);
        $preferred = max($minimum, min($maximum, $preferred));
        [$pool, $required] = $this->characterRules($rule, true);
        if ($rule !== [] && $pool === '') {
            throw new DomainException('上游未提供可用的密码字符类别，无法自动生成，请联系管理员。');
        }
        $alphanumeric = self::UPPER.self::LOWER.self::NUMBERS;
        $pools = $pool === '' ? [$alphanumeric, $alphanumeric.self::SPECIAL, self::NUMBERS] : [$pool];
        $lengths = array_values(array_unique([$preferred, $minimum, $maximum, ...range($minimum, $maximum)]));

        return $this->search('', $lengths, $pools, $required, $rules['password_regex'] ?? null, '开通密码');
    }

    private function snapshot(array $rules, string $field): ?array
    {
        if (! array_key_exists('classic_catalog', $rules)) {
            return null;
        }
        $snapshot = is_array($rules['classic_catalog']) ? ($rules['classic_catalog'][$field] ?? null) : null;
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
        if (! is_array($snapshot) || ! array_key_exists('show', $snapshot)
            || ! in_array($snapshot['show'], [true, false, 0, 1, '0', '1'], true)) {
            throw new DomainException('上游开通规则尚未同步或不可读，无法自动生成，请同步商品后重试。');
        }

        return $snapshot;
    }

    private function remoteRule(?array $snapshot, string $label): array
    {
        if ($snapshot === null) {
            return [];
        }
        $rule = $snapshot['rule'] ?? [];
        if (! is_array($rule) || (($snapshot['show'] ?? false) && $rule === [])) {
            throw new DomainException('上游'.$label.'规则不完整，无法自动生成，请同步商品后重试。');
        }

        return $rule;
    }

    /** @return array{string, list<string>} */
    private function characterRules(array $rule, bool $password): array
    {
        $required = [];
        foreach (['upper' => self::UPPER, 'lower' => self::LOWER, 'num' => self::NUMBERS] + ($password ? ['special' => self::SPECIAL] : []) as $key => $characters) {
            if ($rule === []) {
                continue;
            }
            if (! array_key_exists($key, $rule) || ! in_array($rule[$key], [true, false, 0, 1, '0', '1'], true)) {
                throw new DomainException('上游字符类别规则不完整，无法自动生成，请同步商品后重试。');
            }
            if ($rule[$key]) {
                $required[] = $characters;
            }
        }

        return [implode('', $required), $required];
    }

    /** Bounded candidate sampling; custom regexes are checked, never rewritten or inverted. */
    private function search(string $prefix, array $lengths, array $pools, array $required, mixed $expression, string $label): string
    {
        if ($expression !== null && (! is_string($expression) || @preg_match($this->pattern($expression), '') === false)) {
            throw new DomainException($label.'格式规则不可读，无法自动生成，请联系管理员。');
        }
        foreach ($lengths as $length) {
            if ($length < strlen($prefix)) {
                continue;
            }
            foreach ($pools as $pool) {
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $suffix = '';
                    foreach ($required as $characters) {
                        if (strpbrk($prefix, $characters) === false) {
                            $suffix .= $this->pick($characters);
                        }
                    }
                    if (strlen($prefix.$suffix) > $length) {
                        continue;
                    }
                    while (strlen($prefix.$suffix) < $length) {
                        $suffix .= $this->pick($pool);
                    }
                    for ($index = strlen($suffix) - 1; $index > 0; $index--) {
                        $other = random_int(0, $index);
                        [$suffix[$index], $suffix[$other]] = [$suffix[$other], $suffix[$index]];
                    }
                    $value = $prefix.$suffix;
                    if ($expression === null || preg_match($this->pattern($expression), $value) === 1) {
                        return $value;
                    }
                }
            }
        }
        throw new DomainException($label.'的自定义规则无法自动生成匹配值，请按商品规则手动填写。');
    }

    private function configuredLength(mixed $value, string $label): int
    {
        return $this->integer(in_array($value, [null, '', 0, '0'], true) ? 12 : $value, $label);
    }

    private function integer(mixed $value, string $label): int
    {
        if ((! is_int($value) && ! is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException($label.'配置不可读，无法自动生成，请联系管理员。');
        }

        return (int) $value;
    }

    private function pattern(string $expression): string
    {
        return '/'.str_replace('/', '\/', $expression).'/';
    }

    private function pick(string $characters): string
    {
        return $characters[random_int(0, strlen($characters) - 1)];
    }
}
