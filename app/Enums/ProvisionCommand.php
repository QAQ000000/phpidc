<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The 12 `func` values accepted by ZJMF `POST /provision/default`
 * (zjmf-api-spec §4). Previously these existed only as prose — the single
 * largest source of string drift in the codebase.
 */
enum ProvisionCommand: string
{
    case SUSPEND = 'suspend';
    case UNSUSPEND = 'unsuspend';
    case POWER_ON = 'on';
    case POWER_OFF = 'off';
    case REBOOT = 'reboot';
    case HARD_OFF = 'hard_off';
    case HARD_REBOOT = 'hard_reboot';
    case VNC = 'vnc';
    case REINSTALL = 'reinstall';
    case STATUS = 'status';
    case CRACK_PASSWORD = 'crack_pass';
    case RESCUE_SYSTEM = 'rescueSystem';

    /** Commands that change the power or suspension state of the machine. */
    public function affectsPowerState(): bool
    {
        return in_array($this, [
            self::SUSPEND,
            self::UNSUSPEND,
            self::POWER_ON,
            self::POWER_OFF,
            self::REBOOT,
            self::HARD_OFF,
            self::HARD_REBOOT,
        ], true);
    }

    /** Commands that destroy or replace data on the machine. */
    public function isDestructive(): bool
    {
        return in_array($this, [self::REINSTALL, self::RESCUE_SYSTEM], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
