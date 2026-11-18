<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\QuoteResult;
use App\Exceptions\PurchasePriceChanged;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;

final class PurchaseQuoteConfirmation
{
    public function issue(User $user, Product $product, string $cycle, array $options, QuoteResult $quote): string
    {
        return Crypt::encryptString(json_encode($this->scope($user, $product, $cycle, $options) + [
            'version' => 1, 'price' => $quote->downstreamPrice, 'setup_fee' => $quote->setupFee,
            'currency' => $quote->currency, 'expires' => now()->addMinutes(5)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function read(?string $token, User $user, Product $product, string $cycle, array $options): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        try {
            $confirmed = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            $this->invalid();
        }
        if (! is_array($confirmed) || ($confirmed['version'] ?? null) !== 1
            || ! is_int($confirmed['expires'] ?? null) || $confirmed['expires'] <= now()->getTimestamp()) {
            $this->invalid();
        }
        foreach ($this->scope($user, $product, $cycle, $options) as $key => $value) {
            if (($confirmed[$key] ?? null) !== $value) {
                $this->invalid();
            }
        }

        return $confirmed;
    }

    public function assertMatches(?array $confirmed, User $user, Product $product, string $cycle, array $options, QuoteResult $quote): void
    {
        if ($confirmed === null || ($confirmed['currency'] ?? null) !== $quote->currency
            || ($confirmed['price'] ?? null) !== $quote->downstreamPrice || ($confirmed['setup_fee'] ?? null) !== $quote->setupFee) {
            throw new PurchasePriceChanged($quote, $this->issue($user, $product, $cycle, $options, $quote));
        }
    }

    private function scope(User $user, Product $product, string $cycle, array $options): array
    {
        $selected = [];
        foreach ($options['configoption'] ?? [] as $key => $value) {
            $selected[(int) $key] = (string) (int) $value;
        }
        ksort($selected, SORT_NUMERIC);

        return ['user_id' => $user->id, 'product_id' => $product->id, 'cycle' => $cycle,
            'driver_type' => $product->driver_type->value, 'upstream_api_id' => $product->upstream_api_id,
            'upstream_pid' => $product->upstream_pid,
            'options_hash' => hash('sha256', json_encode($selected, JSON_THROW_ON_ERROR))];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['quote_token' => '报价已过期或配置发生变化，请重新获取本地报价。']);
    }
}
