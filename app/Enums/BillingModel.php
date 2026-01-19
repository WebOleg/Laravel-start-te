<?php

namespace App\Enums;

enum BillingModel: string
{
    case Legacy = 'legacy';
    case Flywheel = 'flywheel';
    case Recovery = 'recovery';

    public const FLYWHEEL_MIN = 1.99;
    public const FLYWHEEL_MAX = 4.95;

    public const RECOVERY_MIN = 29.99;
    public const RECOVERY_MAX = 99.99;

    public static function values(): array
    {
        return array_map(fn(self $c) => $c->value, self::cases());
    }

    public static function isFlywheelAmount(?float $amount): bool
    {
        return $amount !== null && $amount >= self::FLYWHEEL_MIN && $amount <= self::FLYWHEEL_MAX;
    }

    public static function isRecoveryAmount(?float $amount): bool
    {
        return $amount !== null && $amount >= self::RECOVERY_MIN && $amount <= self::RECOVERY_MAX;
    }

    public static function fromAmount(?float $amount): self
    {
        if (self::isFlywheelAmount($amount)) return self::Flywheel;
        if (self::isRecoveryAmount($amount)) return self::Recovery;
        return self::Legacy;
    }
}
