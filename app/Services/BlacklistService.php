<?php

/**
 * Service for checking IBANs, names, and emails against blacklist.
 */

namespace App\Services;

use App\Models\Blacklist;
use App\Models\Debtor;

class BlacklistService
{
    public function __construct(
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Check if IBAN is blacklisted.
     */
    public function isBlacklisted(string $iban): bool
    {
        $hash = $this->ibanValidator->hash($iban);
        return Blacklist::where('iban_hash', $hash)->exists();
    }

    /**
     * Check if name is blacklisted.
     */
    public function isNameBlacklisted(string $firstName, string $lastName): bool
    {
        if (empty($firstName) && empty($lastName)) {
            return false;
        }
        
        return Blacklist::whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
            ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
            ->exists();
    }

    /**
     * Check if email is blacklisted.
     */
    public function isEmailBlacklisted(string $email): bool
    {
        if (empty($email)) {
            return false;
        }
        
        return Blacklist::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists();
    }

    /**
     * Check debtor against all blacklist criteria.
     * Returns array of matched reasons or empty array if not blacklisted.
     *
     * @param Debtor|array $debtor
     * @return array{iban: bool, name: bool, email: bool, reasons: string[]}
     */
    public function checkDebtor($debtor): array
    {
        $result = [
            'iban' => false,
            'name' => false,
            'email' => false,
            'reasons' => [],
        ];

        // Extract data from Debtor model or array
        if ($debtor instanceof Debtor) {
            $iban = $debtor->iban;
            $firstName = $debtor->first_name ?? '';
            $lastName = $debtor->last_name ?? '';
            $email = $debtor->email ?? '';
        } else {
            $iban = $debtor['iban'] ?? '';
            $firstName = $debtor['first_name'] ?? '';
            $lastName = $debtor['last_name'] ?? '';
            $email = $debtor['email'] ?? '';
        }

        // Check IBAN
        if (!empty($iban) && $this->isBlacklisted($iban)) {
            $result['iban'] = true;
            $result['reasons'][] = 'IBAN is blacklisted';
        }

        // Check name
        if (!empty($firstName) && !empty($lastName) && $this->isNameBlacklisted($firstName, $lastName)) {
            $result['name'] = true;
            $result['reasons'][] = 'Name is blacklisted';
        }

        // Check email
        if (!empty($email) && $this->isEmailBlacklisted($email)) {
            $result['email'] = true;
            $result['reasons'][] = 'Email is blacklisted';
        }

        return $result;
    }

    /**
     * Check if debtor matches any blacklist criteria.
     *
     * @param Debtor|array $debtor
     * @return bool
     */
    public function isDebtorBlacklisted($debtor): bool
    {
        $check = $this->checkDebtor($debtor);
        return $check['iban'] || $check['name'] || $check['email'];
    }

    /**
     * Add entry to blacklist with optional personal data.
     */
    public function add(
        string $iban,
        ?string $reason = null,
        ?string $source = null,
        ?int $userId = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $email = null
    ): Blacklist {
        $normalized = $this->ibanValidator->normalize($iban);
        $hash = $this->ibanValidator->hash($iban);

        return Blacklist::updateOrCreate(
            ['iban_hash' => $hash],
            [
                'iban' => $normalized,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'reason' => $reason,
                'source' => $source,
                'added_by' => $userId,
            ]
        );
    }

    /**
     * Add debtor to blacklist.
     *
     * @param Debtor $debtor
     * @param string|null $reason
     * @param string|null $source
     * @return Blacklist
     */
    public function addDebtor(Debtor $debtor, ?string $reason = null, ?string $source = null): Blacklist
    {
        return $this->add(
            iban: $debtor->iban,
            reason: $reason,
            source: $source,
            userId: null,
            firstName: $debtor->first_name,
            lastName: $debtor->last_name,
            email: $debtor->email
        );
    }

    /**
     * Remove entry from blacklist by IBAN.
     */
    public function remove(string $iban): bool
    {
        $hash = $this->ibanValidator->hash($iban);
        return Blacklist::where('iban_hash', $hash)->delete() > 0;
    }

    /**
     * Get blacklist entry by IBAN.
     */
    public function find(string $iban): ?Blacklist
    {
        $hash = $this->ibanValidator->hash($iban);
        return Blacklist::where('iban_hash', $hash)->first();
    }
}
