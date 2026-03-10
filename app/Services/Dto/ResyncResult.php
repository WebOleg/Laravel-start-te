<?php

namespace App\Services\Dto;

final readonly class ResyncResult
{
    public function __construct(
        public bool $archived,
        public int $resetCount,
        public bool $dispatched,
        public int $eligibleCount,
    ) {}
}
