<?php

/**
 * Trait for parsing debtor data from CSV/XLSX rows.
 */

namespace App\Traits;

trait ParsesDebtorData
{
    /**
     * Split "JOHN DOE" into first_name + last_name.
     */
    private function splitFullName(array &$data): void
    {
        if (!empty($data['first_name']) || !empty($data['last_name'])) {
            return;
        }

        if (empty($data['name'])) {
            return;
        }

        $fullName = preg_replace('/\s+/', ' ', trim($data['name']));
        unset($data['name']);

        if (empty($fullName)) {
            return;
        }

        if (str_contains($fullName, ',')) {
            $parts = array_map('trim', explode(',', $fullName, 2));
            if (count($parts) === 2 && !empty($parts[0]) && !empty($parts[1])) {
                $data['last_name'] = $this->normalizeName($parts[0]);
                $data['first_name'] = $this->normalizeName($parts[1]);
                return;
            }
        }

        $parts = array_values(array_filter(explode(' ', $fullName)));

        if (count($parts) === 1) {
            $data['first_name'] = $this->normalizeName($parts[0]);
            $data['last_name'] = $this->normalizeName($parts[0]);
        } elseif (count($parts) === 2) {
            $data['first_name'] = $this->normalizeName($parts[0]);
            $data['last_name'] = $this->normalizeName($parts[1]);
        } else {
            $data['first_name'] = $this->normalizeName(array_shift($parts));
            $data['last_name'] = $this->normalizeName(implode(' ', $parts));
        }
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if (mb_strtoupper($name) === $name && mb_strlen($name) > 2) {
            $name = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }

        return $name;
    }

    private function enrichCountryFromIban(array &$data): void
    {
        if (!empty($data['country']) || empty($data['iban'])) {
            return;
        }

        $iban = preg_replace('/\s+/', '', $data['iban']);

        if (strlen($iban) >= 2) {
            $country = strtoupper(substr($iban, 0, 2));
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                $data['country'] = $country;
            }
        }
    }

    private function castValue(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'amount' => $this->parseAmount($value),
            'birth_date' => $this->parseDate($value),
            'country' => strtoupper(substr(trim($value), 0, 2)),
            'currency' => strtoupper(substr(trim($value), 0, 3)),
            default => trim((string) $value),
        };
    }

    private function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(' ', '', (string) $value);
        $value = preg_replace('/[€$£]/', '', $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (str_contains($value, ',')) {
            if (preg_match('/,\d{1,2}$/', $value)) {
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        }

        $amount = (float) $value;
        return $amount > 0 ? $amount : null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim((string) $value);

        if (is_numeric($value) && $value > 10000 && $value < 100000) {
            return date('Y-m-d', ($value - 25569) * 86400);
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd.m.y', 'd/m/y'];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        $timestamp = strtotime($value);
        return ($timestamp && $timestamp > 0) ? date('Y-m-d', $timestamp) : null;
    }
}
