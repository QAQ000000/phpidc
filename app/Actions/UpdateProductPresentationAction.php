<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductPresentationAction
{
    public function execute(Product $product, array $input, string $reason): Product
    {
        $data = validator([...$input, 'reason' => trim($reason)], ['name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:100000'], 'is_hidden' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:1000']])->validate();
        unset($data['reason']);

        return DB::transaction(function () use ($product, $data, $reason): Product {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(array_keys($data));
            $locked->update($data);
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'product.presentation_update', 'subject_type' => Product::class,
                'subject_id' => $locked->id, 'reason' => $reason, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $locked;
        });
    }
}
