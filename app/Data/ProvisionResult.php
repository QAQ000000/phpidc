<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Result of a driver-side provisioning or lifecycle operation.
 * Drivers return this and mutate nothing (INV-L4).
 */
readonly class ProvisionResult
{
    public function __construct(
        public bool $success,
        public ?string $upstreamInstanceId = null,
        public ?string $primaryIp = null,
        public array $assignedIps = [],
        public array $credentials = [],
        public ?string $errorMessage = null,
        public bool $retryable = false,
        public ?ProvisionOutcome $outcome = null,
        public array $rawResponse = [],
    ) {}

    public static function ok(
        ?string $upstreamInstanceId = null,
        ?string $primaryIp = null,
        array $assignedIps = [],
        array $credentials = [],
        ?ProvisionOutcome $outcome = null,
        array $rawResponse = [],
    ): self {
        return new self(
            success: true,
            upstreamInstanceId: $upstreamInstanceId,
            primaryIp: $primaryIp,
            assignedIps: $assignedIps,
            credentials: $credentials,
            outcome: $outcome ?? ProvisionOutcome::PROVISIONED,
            rawResponse: $rawResponse,
        );
    }

    public static function fail(string $message, bool $retryable = false, array $rawResponse = []): self
    {
        return new self(
            success: false,
            errorMessage: $message,
            retryable: $retryable,
            outcome: ProvisionOutcome::FAILED,
            rawResponse: $rawResponse,
        );
    }

    /** Upstream already reported the requested state; local rows were reconciled. */
    public static function reconciled(array $rawResponse = []): self
    {
        return new self(
            success: true,
            outcome: ProvisionOutcome::RECONCILED,
            rawResponse: $rawResponse,
        );
    }
}
