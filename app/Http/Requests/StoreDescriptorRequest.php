<?php

namespace App\Http\Requests;

use App\Models\TransactionDescriptor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDescriptorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Gateway Strictness: Alphanumeric + spaces only, max 25 chars.
            'descriptor_name' => [
                'required',
                'string',
                'max:25'
            ],
            'descriptor_city' => 'nullable|string|max:13',
            'descriptor_country' => 'nullable|string|size:3', // ISO alpha-3
            'is_default' => [
                'required',
                'boolean',
            ],

            // Conditional Logic: If NOT default, Date is required.
            'year' => [
                'nullable',
                'integer',
                'min:2025',
                Rule::requiredIf(!$this->is_default),
            ],
            'month' => [
                'nullable',
                'integer',
                'min:1',
                'max:12',
                Rule::requiredIf(!$this->is_default),
            ],
            'emp_account_id' => [
                'nullable',
                'exists:emp_accounts,id',
                function ($attribute, $value, $fail) {
                    $this->validateDescriptorUniqueness($value, $fail);
                },
            ],
        ];
    }

    // Cleanup data before validation
    protected function prepareForValidation()
    {
        // If it is default, force year/month to null to match DB constraints
        if ($this->is_default) {
            $this->merge([
                'year' => null,
                'month' => null,
            ]);
        }
    }

    /**
     * Validate descriptor uniqueness based on account-specific scenarios:
     * 1. Account-specific dated (emp_account_id + month + year, is_default=false)
     */
    protected function validateDescriptorUniqueness($empAccountId, $fail): void
    {
        // Only validate if emp_account_id is provided
        if ($empAccountId === null) {
            return;
        }

        $isDefault = filter_var($this->is_default, FILTER_VALIDATE_BOOLEAN);
        
        // Scenario 1: Account-specific dated descriptor (emp_account_id + month + year, is_default=false)
        if (!$isDefault) {
            $query = TransactionDescriptor::where('emp_account_id', $empAccountId)
                ->where('year', $this->year)
                ->where('month', $this->month)
                ->where('is_default', false);
            
            $this->excludeCurrentRecord($query);
            
            if ($query->exists()) {
                $fail("A descriptor for {$this->year}-{$this->month} already exists for this account.");
            }
        }
    }

    /**
     * Exclude the current record from the query if updating
     */
    protected function excludeCurrentRecord($query): void
    {
        $descriptor = $this->route('descriptor');
        if ($descriptor instanceof TransactionDescriptor) {
            $query->where('id', '!=', $descriptor->id);
        } elseif ($descriptor) {
            // Fallback if route model binding is not used
            $query->where('id', '!=', $descriptor);
        }
    }
}
