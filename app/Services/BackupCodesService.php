<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BackupCodesService
{
    /**
     * Configuration for code generation.
     */
    protected const CODE_COUNT = 10;
    protected const CODE_PART_LENGTH = 4;

    /**
     * Generate a fresh set of backup codes for the user.
     *
     * This method generates plaintext codes for the user to save,
     * but stores them securely (hashed) in the database.
     *
     * @param User $user
     * @return array<string> The plaintext codes to display to the user once.
     */
    public function generate(User $user): array
    {
        $plaintextCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = $this->generateSingleCode();

            $plaintextCodes[] = $code;
            $hashedCodes[] = Hash::make($code);
        }

        // Store only the hashed versions
        $user->two_factor_backup_codes = $hashedCodes;
        $user->save();

        return $plaintextCodes;
    }

    /**
     * Verify a backup code and remove it if valid (Single Use).
     *
     * @param User $user
     * @param string $code
     * @return bool
     */
    public function verify(User $user, string $code): bool
    {
        $storedCodes = $user->two_factor_backup_codes ?? [];

        if (empty($storedCodes)) {
            return false;
        }

        // Normalize input: verify logic usually expects uppercase to match generation format
        $inputCode = strtoupper(trim($code));

        foreach ($storedCodes as $key => $hashedCode) {
            if (Hash::check($inputCode, $hashedCode)) {
                // Code is valid. Remove it immediately (Single Use Logic).
                unset($storedCodes[$key]);

                // Re-index array to prevent JSON gap issues and save
                $user->two_factor_backup_codes = array_values($storedCodes);
                $user->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Get the number of remaining valid backup codes.
     *
     * @param User $user
     * @return int
     */
    public function getRemainingCount(User $user): int
    {
        return count($user->two_factor_backup_codes ?? []);
    }

    /**
     * Generate a single code in XXXX-XXXX format.
     *
     * @return string
     */
    protected function generateSingleCode(): string
    {
        // Example: "A1B2-C3D4"
        return Str::upper(Str::random(self::CODE_PART_LENGTH)) .
            '-' .
            Str::upper(Str::random(self::CODE_PART_LENGTH));
    }
}
