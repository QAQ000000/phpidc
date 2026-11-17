<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ValidateCheckoutAction
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $user, int $productId, array $input): array
    {
        return DB::transaction(function () use ($user, $productId, $input): array {
            // INV-W3 / INV-L1: the first row lock belongs to the wallet owner.
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $product = Product::query()->findOrFail($productId);
            $rules = $product->provisioning_rules ?? [];

            $hostnameRules = [
                'bail',
                ($rules['hostname_mode'] ?? 'upstream_auto') === 'custom' ? 'required' : 'nullable',
                'string',
            ];
            if (isset($rules['hostname_regex'])) {
                $hostnameRules[] = 'regex:'.$this->pattern($rules['hostname_regex']);
            }

            $requiresPassword = isset($rules['password_min_len'])
                || isset($rules['password_max_len'])
                || isset($rules['password_regex']);
            $passwordRules = ['bail', $requiresPassword ? 'required' : 'nullable', 'string'];
            if (isset($rules['password_min_len'])) {
                $passwordRules[] = 'min:'.$rules['password_min_len'];
            }
            if (isset($rules['password_max_len'])) {
                $passwordRules[] = 'max:'.$rules['password_max_len'];
            }
            if (isset($rules['password_regex'])) {
                $passwordRules[] = 'regex:'.$this->pattern($rules['password_regex']);
            }

            // Validation must finish before any invoice, wallet or task write.
            return Validator::make($input, [
                'hostname' => $hostnameRules,
                'password' => $passwordRules,
            ])->validate();
        });
    }

    private function pattern(string $expression): string
    {
        // Charter §4 stores expressions without delimiters, including literal /.
        return '/'.str_replace('/', '\/', $expression).'/';
    }
}
