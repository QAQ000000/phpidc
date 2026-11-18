<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Instance;

/** A completed delivery leaves no managed resource or recurring service obligation. */
interface OneTimeDeliverable
{
    /**
     * Validate current local delivery evidence without upstream I/O or mutations.
     * Return stable business facts only; never credentials, ciphertext or secret hashes.
     *
     * @return array<string, mixed>
     */
    public function deliveryEvidence(Instance $instance): array;
}
