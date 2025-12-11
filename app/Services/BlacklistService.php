<?php

/**
 * Service for checking IBANs against blacklist.
 */

namespace App\Services;

use App\Models\Blacklist;

class BlacklistService
{
    public function __construct(
        private IbanValidator $ibanValidator
    ) {}

    public function isBlacklisted(string $iban): bool
    {
        $hash = $this->ibanValidator->hash($iban);
        return Blacklist::where('iban_hash', $hash)->exists();
    }

    public function add(string $iban, ?string $reason = null, ?string $source = null, ?int $userId = null): Blacklist
    {
        $normalized = $this->ibanValidator->normalize($iban);
        $hash = $this->ibanValidator->hash($iban);

        return Blacklist::updateOrCreate(
            ['iban_hash' => $hash],
            [
                'iban' => $normalized,
                'reason' => $reason,
                'source' => $source,
                'added_by' => $userId,
            ]
        );
    }

    public function remove(string $iban): bool
    {
        $hash = $this->ibanValidator->hash($iban);
        return Blacklist::where('iban_hash', $hash)->delete() > 0;
    }
}
