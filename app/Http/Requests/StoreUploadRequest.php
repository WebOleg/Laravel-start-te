<?php

/**
 * Form request validation for file uploads.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\BillingModel;
use Illuminate\Validation\Rule;

class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:51200',
                'mimes:csv,txt,xlsx,xls',
            ],
            'billing_model' => ['sometimes', Rule::enum(BillingModel::class)],
            'emp_account_id' => ['sometimes', 'nullable', 'integer', 'exists:emp_accounts,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => 'File size cannot exceed 50MB.',
            'file.mimes' => 'Only CSV, TXT and Excel files (xlsx, xls) are allowed.',
            'emp_account_id.exists' => 'Selected EMP account does not exist.',
        ];
    }
}
