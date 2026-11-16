<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Upstream instance snapshot, replacing the previous bare-array return of
 * FulfillmentDriver::syncInfo(). Field names mirror ZJMF `/host/header` host_data.
 */
readonly class InstanceInfoResult
{
    public function __construct(
        public string $upstreamStatus,
        public ?string $domainStatus = null,
        public ?string $primaryIp = null,
        public array $assignedIps = [],
        public ?string $username = null,
        public ?string $password = null,
        public ?int $port = null,
        public ?string $os = null,
        public ?string $nextDueDate = null,
        public ?string $suspendReason = null,
        public array $customFields = [],
        public ?string $passwordRule = null,
        public bool $allowUpgradeConfig = false,
        public array $panelWidgets = [],
        public array $rawResponse = [],
    ) {}
}
