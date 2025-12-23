<?php

/**
 * Form request validation for file uploads.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => 'File size cannot exceed 50MB.',
            'file.mimes' => 'Only CSV, TXT and Excel files (xlsx, xls) are allowed.',
        ];
    }
}
