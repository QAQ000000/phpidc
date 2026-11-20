<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

class RequestCustomerPasswordResetAction
{
    /** @return 'sent'|'throttled'|'retryable'|'rejected'|'uncertain' */
    public function execute(User $target, User $actor, ?string $reason = null): string
    {
        return app(CustomerIdentityAction::class)->requestAdminPasswordReset($target, $actor, $reason);
    }
}
