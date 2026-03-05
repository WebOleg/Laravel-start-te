<?php

namespace App\Services\Dto;

final readonly class ResyncEligibility
{
    public function __construct(
        public bool $allowed,
        public string $reason,
        public int $eligibleCount = 0,
        public ?string $billingModel = null,
    ) {}

    public function denied(): bool
    {
        return !$this->allowed;
    }
}
