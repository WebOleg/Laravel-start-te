<?php

/**
 * Form request validation for file uploads.
 *
 * Note on is_30d_cool:
 * This flag enables the 30-day cooling period at the upload level, meaning a debtor
 * whose IBAN was billed within the last 30 days will be skipped at import time and
 * blocked at billing time.
 *
 * is_30d_cool is ONLY meaningful for the Legacy billing model:
 *  - Legacy has no built-in billing cycle control, so the 30-day cooldown is the
 *    only rate-limiting mechanism available.
 *  - Flywheel already uses DebtorProfile->due() to control billing cycles — the
 *    30-day cooldown is redundant and harmful.
 *  - Recovery is designed to retry failed payments — the 30-day cooldown directly
 *    contradicts its purpose by freezing declined/errored debtors for 30 days.
 *
 * Passing is_30d_cool with any value (true or false) for billing_model = flywheel
 * or recovery is rejected at the API level. Legacy accepts both true and false.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\BillingModel;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'tether_instance_id' => ['sometimes', 'nullable', 'integer', 'exists:tether_instances,id'],
            'is_30d_cool' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /**
     * Reject is_30d_cool when set to any value (true or false) for non-legacy billing models.
     *
     * is_30d_cool is only meaningful for Legacy uploads. Flywheel uses DebtorProfile->due()
     * to manage billing cycles, and Recovery is designed to retry failed payments — applying
     * a 30-day cooldown to either model is incorrect and must be blocked at the API level.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $billingModel = $this->input('billing_model', BillingModel::Legacy->value);

            if ($billingModel === BillingModel::Legacy->value) {
                return;
            }

            $is30dCool = filter_var($this->input('is_30d_cool'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($is30dCool !== null) {
                $v->errors()->add(
                    'is_30d_cool',
                    'The 30-day cooling period is only applicable to the Legacy billing model. ' .
                    'Flywheel and Recovery models manage their own billing cycles independently. ' .
                    'Do not select 30 days cool for non-legacy uploads.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload.',
            'file.file' => 'The upload must be a valid file.',
            'file.max' => 'File size cannot exceed 50MB.',
            'file.mimes' => 'Only CSV, TXT and Excel files (xlsx, xls) are allowed.',
            'emp_account_id.exists' => 'Selected EMP account does not exist.',
            'tether_instance_id.exists' => 'Selected Tether instance does not exist.',
        ];
    }
}
