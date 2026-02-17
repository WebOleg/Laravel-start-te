<?php

namespace App\Services;

use App\Models\TransactionDescriptor;
use DateTimeInterface;

class DescriptorService
{
    /**
     * Determine the active descriptor for a given date.
     */
    public function getActiveDescriptor(?DateTimeInterface $date = null): ?TransactionDescriptor
    {
        $date = $date ?? now();
        $specific = TransactionDescriptor::specificFor($date)->first();

        if ($specific) {
            return $specific;
        }

        return TransactionDescriptor::defaultFallback()->first();
    }

    /**
     * If setting a new default, un-set any existing default.
     * Maintains one global default (emp_account_id = null) and one per emp_account_id.
     */
    public function ensureSingleDefault(bool $isNewDefault, ?int $ignoreId = null, ?int $empAccountId = null): void
    {
        if (!$isNewDefault) {
            return;
        }

        $query = TransactionDescriptor::where('is_default', true);

        if ($empAccountId !== null) {
            $query->where('emp_account_id', $empAccountId);
        } else {
            $query->whereNull('emp_account_id');
        }

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $query->update(['is_default' => false]);
    }
}
