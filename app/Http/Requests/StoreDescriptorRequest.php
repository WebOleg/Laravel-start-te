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
            ],
            'emp_account_id' => [
                'required',
                'exists:emp_accounts,id',
                function ($attribute, $value, $fail) {
                    $query = TransactionDescriptor::where('emp_account_id', $value);
                    $isDefault = filter_var($this->is_default, FILTER_VALIDATE_BOOLEAN);
                    
                    // If it's a default descriptor, check for other defaults
                    if ($isDefault) {
                        $query = $query->where('is_default', true);
                    } else {
                        // If it's a dated descriptor, check for same year/month combination
                        $query = $query->where('year', $this->year)
                                    ->where('month', $this->month)
                                    ->where('is_default', false);
                    }
                    
                    // Exclude current record if updating
                    if ($this->route('descriptor')) {
                        $query = $query->where('id', '!=', $this->route('descriptor')->id);
                    }
                    
                    if ($query->exists()) {
                        if ($this->is_default) {
                            $fail("A default descriptor already exists for this account.");
                        } else {
                            $fail("A descriptor for {$this->year}-{$this->month} already exists for this account.");
                        }
                    }
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
}
