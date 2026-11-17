<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use App\Support\ProvisioningValidator;
use Illuminate\Support\Facades\DB;

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

            return app(ProvisioningValidator::class)->validate($product, $input);
        });
    }
}
