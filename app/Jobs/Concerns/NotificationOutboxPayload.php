<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

trait NotificationOutboxPayload
{
    public ?string $legacyKey = null;

    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['job']);
        if ($this->deliveryId !== null) {
            foreach (['userId', 'event', 'channel', 'target', 'variables', 'bindingId', 'invoiceId', 'fallback', 'legacyKey'] as $key) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    public function __unserialize(array $values): void
    {
        // Promoted constructor defaults are not property defaults during unserialize.
        $this->__construct();
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    }
}
