<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\PaymentPostingKind;

/** Facts produced by an actual posting transaction, not caller-selected conclusions. */
readonly class PaymentPostingResult
{
    public function __construct(
        public PaymentCallbackOutcome $outcome,
        public ?string $reason = null,
        public ?PaymentPostingKind $kind = null,
        public bool $closedOwner = false,
        public ?InvoiceStatus $invoiceStatusBefore = null,
        public ?int $paymentTransactionId = null,
        public ?int $settlementTransactionId = null,
        public ?int $principalCreditTransactionId = null,
        public ?int $excessCreditTransactionId = null,
        public ?int $fulfillmentTaskId = null,
        public ?int $paymentAttemptId = null,
    ) {}

    public function posted(): bool
    {
        return $this->kind !== null && $this->outcome->isAccepted();
    }
}
