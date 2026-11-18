<?php

declare(strict_types=1);

namespace App\Contracts;

interface BoundUpstreamEndpoint
{
    /** @return array{upstream_api_id: int, base_url: string, protocol: string} The endpoint actually used by this driver. */
    public function endpointIdentity(): array;
}
