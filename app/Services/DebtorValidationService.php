<?php

/**
 * Service for validating debtor records before gateway sync.
 */

namespace App\Services;

use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Support\Facades\Cache;

class DebtorValidationService
{
    public const NAME_MAX_LENGTH = 35;
    public const INVALID_NAME_PATTERN = '/[0-9*#@$%^&+=\[\]{}|\\\\<>áàâäçèéêëîïíóòôöúùûüÿñÁÀÂÄÇÈÉÊËÎÏÍÓÒÔÖÚÙÛÜŸÑ]/';

    public function __construct(
        private IbanValidator $ibanValidator,
        private BlacklistService $blacklistService
    ) {}

    public function validateDebtor(Debtor $debtor): array
    {
        $errors = [];

        $errors = array_merge($errors, $this->validateRequiredFields($debtor));
        $errors = array_merge($errors, $this->validateName($debtor));
        $errors = array_merge($errors, $this->validateIban($debtor));
        $errors = array_merge($errors, $this->validateAmount($debtor));
        $errors = array_merge($errors, $this->validateEmail($debtor));
        $errors = array_merge($errors, $this->validateCountry($debtor));
        $errors = array_merge($errors, $this->validateEncoding($debtor));
        $errors = array_merge($errors, $this->validateBlacklist($debtor));

        return $errors;
    }

    public function validateAndUpdate(Debtor $debtor): Debtor
    {
        $errors = $this->validateDebtor($debtor);

        if (empty($errors)) {
            $debtor->validation_status = Debtor::VALIDATION_VALID;
            $debtor->validation_errors = null;
        } else {
            $debtor->validation_status = Debtor::VALIDATION_INVALID;
            $debtor->validation_errors = $errors;
        }

        $debtor->validated_at = now();

        Cache::forget("billing:lock:validation:{$debtor->id}");

        $debtor->save();

        return $debtor;
    }

    public function validateUpload(Upload $upload): array
    {
        $stats = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
        ];

        $upload->debtors()->chunk(100, function ($debtors) use (&$stats) {
            foreach ($debtors as $debtor) {
                $this->validateAndUpdate($debtor);
                $stats['total']++;

                if ($debtor->validation_status === Debtor::VALIDATION_VALID) {
                    $stats['valid']++;
                } else {
                    $stats['invalid']++;
                }
            }
        });

        return $stats;
    }

    protected function validateRequiredFields(Debtor $debtor): array
    {
        $errors = [];

        if (empty($debtor->iban)) {
            $errors[] = 'IBAN is required';
        }

        if (empty($debtor->first_name) && empty($debtor->last_name)) {
            $errors[] = 'Name is required';
        }

        if (empty($debtor->amount) || (float) $debtor->amount < 1) {
            $errors[] = 'Amount is required';
        }

        // THR-9: postcode, city, address removed from required fields

        return $errors;
    }

    protected function validateName(Debtor $debtor): array
    {
        $errors = [];

        // Validate individual name lengths
        if (!empty($debtor->first_name) && strlen($debtor->first_name) > self::NAME_MAX_LENGTH) {
            $errors[] = 'First name cannot exceed ' . self::NAME_MAX_LENGTH . ' characters';
        }
        if (!empty($debtor->last_name) && strlen($debtor->last_name) > self::NAME_MAX_LENGTH) {
            $errors[] = 'Last name cannot exceed ' . self::NAME_MAX_LENGTH . ' characters';
        }

        // Validate individual fields for invalid characters
        if (!empty($debtor->first_name)) {
            if (preg_match(self::INVALID_NAME_PATTERN, $debtor->first_name)) {
                $errors[] = 'First name contains numbers or symbols';
            }
        }

        if (!empty($debtor->last_name)) {
            if (preg_match(self::INVALID_NAME_PATTERN, $debtor->last_name)) {
                $errors[] = 'Last name contains numbers or symbols';
            }
        }

        return $errors;
    }

    protected function validateIban(Debtor $debtor): array
    {
        $errors = [];

        if (empty($debtor->iban)) {
            return $errors;
        }

        $result = $this->ibanValidator->validate($debtor->iban);

        if (!$result['valid']) {
            $errorMsg = implode(', ', $result['errors']);
            $errors[] = 'IBAN is invalid: ' . $errorMsg;
            return $errors;
        }

        if (!$result['is_sepa']) {
            $errors[] = 'Country ' . $result['country_code'] . ' is not in SEPA zone';
        }

        return $errors;
    }

    protected function validateAmount(Debtor $debtor): array
    {
        $errors = [];

        if ($debtor->amount === null) {
            return $errors;
        }

        if ((float) $debtor->amount < 1) {
            $errors[] = 'Amount must be at least 1.00';
        }

        if ((float) $debtor->amount > 50000) {
            $errors[] = 'Amount exceeds maximum limit (50,000)';
        }

        return $errors;
    }

    protected function validateEmail(Debtor $debtor): array
    {
        $errors = [];

        if (empty($debtor->email)) {
            return $errors;
        }

        if (!filter_var($debtor->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email format is invalid';
        }

        return $errors;
    }

    protected function validateCountry(Debtor $debtor): array
    {
        $errors = [];

        if (empty($debtor->country)) {
            return $errors;
        }

        if (!$this->ibanValidator->isSepaCountry($debtor->country)) {
            $errors[] = 'Country ' . $debtor->country . ' is not supported for SEPA';
        }

        return $errors;
    }

    protected function validateEncoding(Debtor $debtor): array
    {
        $errors = [];
        $fieldsWithIssues = [];

        $fieldsToCheck = [
            'first_name' => 'Name',
            'last_name' => 'Name',
            'city' => 'City',
            'street' => 'Street',
            'address' => 'Address',
            'province' => 'Province',
        ];

        foreach ($fieldsToCheck as $field => $label) {
            $value = $debtor->{$field};
            if (!empty($value) && $this->hasBrokenEncoding($value)) {
                if (!in_array($label, $fieldsWithIssues)) {
                    $fieldsWithIssues[] = $label;
                }
            }
        }

        if (!empty($fieldsWithIssues)) {
            $fields = implode(', ', $fieldsWithIssues);
            $errors[] = $fields . ' contains encoding issues (broken characters)';
        }

        return $errors;
    }

    protected function hasBrokenEncoding(string $value): bool
    {
        if (preg_match('/[\x{FFFD}]/u', $value)) {
            return true;
        }

        if (preg_match('/\xC3[\x80-\xBF]/', $value)) {
            return true;
        }

        if (strpos($value, "\xEF\xBF\xBD") !== false) {
            return true;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Validate against blacklist (IBAN + name + email).
     */
    protected function validateBlacklist(Debtor $debtor): array
    {
        $errors = [];

        $check = $this->blacklistService->checkDebtor($debtor);

        foreach ($check['reasons'] as $reason) {
            $errors[] = $reason;
        }

        return $errors;
    }
}
