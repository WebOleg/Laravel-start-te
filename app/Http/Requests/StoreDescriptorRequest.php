<?php

namespace App\Http\Requests;

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
            'is_default' => 'required|boolean',

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
                // Prevent duplicate months (Unique composite index check)
                Rule::unique('transaction_descriptors')
                    ->where('year', $this->year)
                    ->ignore($this->route('descriptor')), // Ignore self on update
            ],
            'emp_account_id' => [
                'required',
                'exists:emp_accounts,id'
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
}
