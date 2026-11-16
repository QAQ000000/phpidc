<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\GatewayChargeResult;
use App\Data\VerifiedPaymentResult;
use App\Models\Invoice;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Stable gateway identifier persisted on invoices — e.g. `epay_alipay`.
     * Injected via the constructor, never inferred by the adapter itself
     * (external-drivers-spec §1.4).
     */
    public function getIdentifier(): string;

    public function isEnabled(): bool;

    /** @param string $amount Fixed-point decimal string, e.g. "10.00" */
    public function charge(Invoice $invoice, string $amount): GatewayChargeResult;

    /**
     * Protocol verification ONLY. Must not read or write invoices, users,
     * payment_callbacks or fulfillment_tasks (INV-L2).
     */
    public function verifyCallback(Request $request): VerifiedPaymentResult;
}
