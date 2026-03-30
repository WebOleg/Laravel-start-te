<?php

/**
 * Service for checking IBANs, names, emails and BICs against blacklist.
 */

namespace App\Services;

use App\Models\Blacklist;
use App\Models\BicBlacklist;
use App\Models\Debtor;

class BlacklistService
{
    private ?array $ibanHashes = null;
    private ?array $emails = null;
    private ?array $names = null;
    private ?array $bics = null;
    private ?array $bicPrefixes = null;

    public function __construct(
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Load all blacklist data into memory.
     */
    public function preload(): void
    {
        $all = Blacklist::select('iban_hash', 'first_name', 'last_name', 'email', 'bic')->get();

        $this->ibanHashes = $all->pluck('iban_hash')->filter()->flip()->toArray();
        $this->emails     = $all->pluck('email')->filter()
                                ->mapWithKeys(fn($e) => [strtolower($e) => true])->toArray();
        $this->names      = $all->filter(fn($r) => $r->first_name && $r->last_name)
                                ->mapWithKeys(fn($r) => [strtolower($r->first_name).'|'.strtolower($r->last_name) => true])
                                ->toArray();
        $this->bics       = $all->pluck('bic')->filter()
                                ->mapWithKeys(fn($b) => [strtolower($b) => true])->toArray();

        // BicBlacklist table (exact + prefix entries)
        $bicBl = BicBlacklist::all();
        $this->bicPrefixes = $bicBl->pluck('bic')->filter()
                                   ->map(fn($b) => strtoupper($b))->values()->toArray();
    }

    /**
     * Check if IBAN is blacklisted.
     */
    public function isBlacklisted(string $iban): bool
    {
        $hash = $this->ibanValidator->hash($iban);
        if ($this->ibanHashes !== null) {
            return isset($this->ibanHashes[$hash]);
        }
        return Blacklist::where('iban_hash', $hash)->exists();
    }

    /**
     * Check if name is blacklisted.
     */
    public function isNameBlacklisted(string $firstName, string $lastName): bool
    {
        if (empty($firstName) && empty($lastName)) return false;
        if ($this->names !== null) {
            return isset($this->names[strtolower($firstName).'|'.strtolower($lastName)]);
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
        if (empty($email)) return false;
        if ($this->emails !== null) {
            return isset($this->emails[strtolower($email)]);
        }
        return Blacklist::whereRaw('LOWER(email) = ?', [strtolower($email)])->exists();
    }

    /**
     * Check if BIC is blacklisted.
     * Checks both bic_blacklists table (exact + prefix) and legacy blacklists table.
     */
    public function isBicBlacklisted(string $bic): bool
    {
        if (empty($bic)) return false;

        if ($this->bics !== null) {
            $upper = strtoupper($bic);
            // Exact match from legacy blacklists table
            if (isset($this->bics[strtolower($bic)])) return true;
            // Exact + prefix match from bic_blacklists table
            foreach ($this->bicPrefixes as $prefix) {
                if (str_starts_with($upper, $prefix)) return true;
            }
            return false;
        }

        if (BicBlacklist::isBlacklisted($bic)) return true;
        return Blacklist::whereRaw('LOWER(bic) = ?', [strtolower($bic)])->exists();
    }

    /**
     * Check debtor against all blacklist criteria.
     * Returns array of matched reasons or empty array if not blacklisted.
     *
     * @param Debtor|array $debtor
     * @return array{iban: bool, name: bool, email: bool, bic: bool, reasons: string[]}
     */
    public function checkDebtor($debtor): array
    {
        $result = [
            'iban' => false,
            'name' => false,
            'email' => false,
            'bic' => false,
            'reasons' => [],
        ];

        if ($debtor instanceof Debtor) {
            $iban = $debtor->iban;
            $firstName = $debtor->first_name ?? '';
            $lastName = $debtor->last_name ?? '';
            $email = $debtor->email ?? '';
            $bic = $debtor->bic ?? '';
        } else {
            $iban = $debtor['iban'] ?? '';
            $firstName = $debtor['first_name'] ?? '';
            $lastName = $debtor['last_name'] ?? '';
            $email = $debtor['email'] ?? '';
            $bic = $debtor['bic'] ?? '';
        }

        if (!empty($iban) && $this->isBlacklisted($iban)) {
            $result['iban'] = true;
            $result['reasons'][] = 'IBAN is blacklisted';
        }

        if (!empty($firstName) && !empty($lastName) && $this->isNameBlacklisted($firstName, $lastName)) {
            $result['name'] = true;
            $result['reasons'][] = 'Name is blacklisted';
        }

        if (!empty($email) && $this->isEmailBlacklisted($email)) {
            $result['email'] = true;
            $result['reasons'][] = 'Email is blacklisted';
        }

        if (!empty($bic) && $this->isBicBlacklisted($bic)) {
            $result['bic'] = true;
            $result['reasons'][] = 'BIC is blacklisted';
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
        return $check['iban'] || $check['name'] || $check['email'] || $check['bic'];
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
