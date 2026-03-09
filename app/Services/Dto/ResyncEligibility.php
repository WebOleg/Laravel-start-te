<?php

namespace App\Services\Dto;

final readonly class ResyncEligibility
{
    // Denial reason codes for structured status mapping
    public const CODE_MODEL_NOT_SUPPORTED = 'model_not_supported';
    public const CODE_NO_LEGACY_DEBTORS = 'no_legacy_debtors';
    public const CODE_LOCK = 'lock';
    public const CODE_PROCESSING = 'processing';
    public const CODE_CAP = 'cap';
    public const CODE_COOLDOWN = 'cooldown';
    public const CODE_NO_ELIGIBLE = 'no_eligible';

    public function __construct(
        public bool $allowed,
        public string $reason,
        public int $eligibleCount = 0,
        public ?string $billingModel = null,
        public ?string $code = null,
    ) {}

    public function denied(): bool
    {
        return !$this->allowed;
    }
}
