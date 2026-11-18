<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Support\RenewalRecovery;
use Illuminate\Validation\ValidationException;

final class DescribeRenewalRecoveryAction
{
    public function execute(Invoice $invoice): ?array
    {
        if ($invoice->type !== InvoiceType::RENEW) {
            return null;
        }
        $guard = app(RenewalRecovery::class);
        try {
            $chain = $guard->chain($invoice);
            $current = $chain->last();
            $guard->eligible($current, $current->instance()->firstOrFail());
            $reason = null;
        } catch (ValidationException $error) {
            $reason = implode(' ', $error->validator->errors()->all());
            $chain ??= collect([$invoice]);
            $current ??= $invoice;
        }

        return ['invoices' => $chain, 'current' => $current, 'reason' => $reason, 'eligible' => $reason === null];
    }
}
