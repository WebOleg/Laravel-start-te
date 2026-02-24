<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookRelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emp_account_ids'   => ['required', 'array', 'min:1'],
            'emp_account_ids.*' => ['integer', 'exists:emp_accounts,id'],

            'domain' => ['required', 'string', 'max:255'],
            'target' => ['required', 'url', 'max:255'],
        ];
    }
}
