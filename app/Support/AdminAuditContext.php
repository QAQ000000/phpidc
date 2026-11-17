<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class AdminAuditContext
{
    /** @return array{ip: ?string, request_id: ?string} */
    public static function fields(bool $webActor): array
    {
        if (! $webActor) {
            return ['ip' => null, 'request_id' => null];
        }
        $request = request();
        if (! $request->attributes->has('admin_audit_id')) {
            $request->attributes->set('admin_audit_id', (string) Str::uuid());
        }

        return ['ip' => $request->ip(), 'request_id' => $request->attributes->get('admin_audit_id')];
    }
}
