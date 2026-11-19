<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use App\Support\ProvisioningValidator;

final class ValidateCheckoutAction
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $user, int $productId, array $input): array
    {
        $product = Product::query()->findOrFail($productId);

        return app(ProvisioningValidator::class)->validate($product, $input);
    }
}
