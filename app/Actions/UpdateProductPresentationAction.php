<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateProductPresentationAction
{
    public function execute(Product $product, array $input, string $reason, ?User $actor = null): Product
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('cms.view');
            Gate::forUser($actor)->authorize('cms.edit');
            Gate::forUser($actor)->authorize('cms.publish');
        }
        $reason = trim($reason);
        $data = validator([...$input, 'reason' => $reason], ['name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:100000'], 'is_hidden' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'max:255']])->validate();
        unset($data['reason']);

        return DB::transaction(function () use ($product, $data, $reason, $actor): Product {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(array_keys($data));
            $locked->update($data);
            DB::table('audit_logs')->insert(['actor_type' => $actor === null ? 'system' : 'user', 'actor_id' => $actor?->id,
                'action' => 'product.presentation_update', 'subject_type' => Product::class,
                'subject_id' => $locked->id, 'reason' => $reason, 'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

            return $locked;
        });
    }
}
