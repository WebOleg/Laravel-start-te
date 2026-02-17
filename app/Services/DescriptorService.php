<?php

namespace App\Services;

use App\Models\TransactionDescriptor;
use DateTimeInterface;

class DescriptorService
{
    /**
     * Determine the active descriptor for a given date and EMP account.
     *
     * Three-tier fallback:
     * 1. Specific descriptor for this month/year with matching emp_account_id
     * 2. Default descriptor (is_default=true) for this emp_account_id
     * 3. Global default descriptor (is_default=true with emp_account_id=null)
     */
    public function getActiveDescriptor(?DateTimeInterface $date = null, ?int $empAccountId = null): ?TransactionDescriptor
    {
        $date = $date ?? now();

        // Case 1: Specific descriptor for this month/year with matching emp_account_id
        if ($empAccountId !== null) {
            $specific = TransactionDescriptor::specificFor($date)
                ->where('emp_account_id', $empAccountId)
                ->first();

            if ($specific) {
                return $specific;
            }

            // Case 2: Default descriptor for this emp_account_id
            $empDefault = TransactionDescriptor::where('is_default', true)
                ->where('emp_account_id', $empAccountId)
                ->first();

            if ($empDefault) {
                return $empDefault;
            }
        }

        // Case 3: Global default descriptor (emp_account_id = null)
        return TransactionDescriptor::where('is_default', true)
            ->whereNull('emp_account_id')
            ->first();
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
