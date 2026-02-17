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
     */
    public function ensureSingleDefault(bool $isNewDefault, ?int $ignoreId = null, ?int $empAccountId = null): void
    {
        if ($isNewDefault && $empAccountId !== null) {
            TransactionDescriptor::where('is_default', true)
                                ->where('emp_account_id', $empAccountId)
                                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                                ->update(['is_default' => false]);
        } else if ($isNewDefault && $empAccountId === null) {
            TransactionDescriptor::where('is_default', true)
                                ->whereNull('emp_account_id')
                                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                                ->update(['is_default' => false]);
        }
    }
}
