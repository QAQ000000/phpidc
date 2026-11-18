<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** @phpstan-type ReceivableProjection array{id: int, user_id: int, invoice_id: int|null, principal: string, settled: string, reversed: string, remaining: string, valid: bool, error: string|null, version: string} */
class AffiliateReceivableBalance
{
    /** @return Collection<int, ReceivableProjection> */
    public function forUser(int $userId): Collection
    {
        $rows = collect();
        FinancialTransaction::where('user_id', $userId)->where('type', TransactionType::AFFILIATE_CLAWBACK)->orderBy('id')
            ->chunkById((int) config('receivables.root_chunk_size', 100), function (Collection $roots) use (&$rows): void {
                $rows = $rows->union($this->many($roots));
            });

        return $rows;
    }

    public function hasOutstanding(int $userId): bool
    {
        return app(ReceivableSnapshot::class)->read($userId, 90)['has_outstanding'];
    }

    public function scanUser(int $userId, int $seconds, array $captureIds = []): array
    {
        return app(ReceivableEvidenceScanner::class)->scanUser($userId, $seconds, $captureIds);
    }

    /** @return ReceivableProjection */
    public function project(FinancialTransaction $root): array
    {
        return $this->many(collect([$root]))->get($root->id);
    }

    public function assertValid(array $projection): void
    {
        if (! $projection['valid']) {
            throw ValidationException::withMessages(['operation' => '应收证据不完整或不一致，请人工核对。']);
        }
    }

    /** @param Collection<int, FinancialTransaction> $roots
     * @return Collection<int, ReceivableProjection>
     */
    public function many(Collection $roots): Collection
    {
        return app(ReceivableEvidenceScanner::class)->project($roots);
    }

    public static function receiptKey(string $channel, string $reference): string
    {
        return hash('sha256', strtolower(trim($channel))."\0".strtoupper(trim($reference)));
    }
}
