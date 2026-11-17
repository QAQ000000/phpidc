<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DriverType;
use App\Models\Product;
use App\Models\RedemptionCode;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportRedemptionCodesAction
{
    /** Trusted server-operator command, not a public customer permission.
     * @param  list<string>  $codes
     */
    public function execute(Product $product, array $codes, string $reason): int
    {
        if (trim($reason) === '') {
            throw new DomainException('An import reason is required.');
        }
        if (! in_array($product->driver_type, [DriverType::REDEMPTION, DriverType::SUB2API], true) || $product->upstream_api_id !== null) {
            throw new DomainException('Select a local card product.');
        }

        return DB::transaction(function () use ($product, $codes, $reason): int {
            $count = 0;
            $batch = (string) Str::ulid();
            foreach ($codes as $code) {
                if (trim($code) === '') {
                    continue;
                }
                $row = RedemptionCode::firstOrCreate(['product_id' => $product->id,
                    'code_hash' => hash_hmac('sha256', $code, config('app.key'))],
                    ['code' => $code, 'batch_no' => $batch, 'status' => 'available']);
                if ($row->wasRecentlyCreated) {
                    $count++;
                }
            }
            DB::table('audit_logs')->insert(['actor_type' => 'system', 'action' => 'cards.import', 'subject_type' => Product::class,
                'subject_id' => $product->id, 'reason' => $reason, 'after' => json_encode(['imported' => $count, 'batch' => $batch]), 'created_at' => now(), 'updated_at' => now()]);

            return $count;
        });
    }
}
