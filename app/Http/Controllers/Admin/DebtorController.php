<?php

/**
 * Admin controller for debtor management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtorResource;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Services\DebtorValidationService;
use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class DebtorController extends Controller
{
    private const FIELD_MAP = [
        'iban' => 'iban',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'email' => 'email',
        'phone' => 'phone',
        'telephone' => 'phone',
        'address' => 'address',
        'street' => 'street',
        'street_number' => 'street_number',
        'postcode' => 'postcode',
        'postal_code' => 'postcode',
        'city' => 'city',
        'province' => 'province',
        'country' => 'country',
        'amount' => 'amount',
        'currency' => 'currency',
        'bank_name' => 'bank_name',
        'bic' => 'bic',
        'external_reference' => 'external_reference',
    ];

    public function __construct(
        private DebtorValidationService $validationService,
        private IbanValidator $ibanValidator
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Debtor::with([
            'upload',
            'debtorProfile',
            'latestVopLog.bankReference',
            'latestBillingAttempt.empAccount',
        ]);

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->input('validation_status'));
        }

        if ($request->has('country')) {
            $query->where('country', $request->input('country'));
        }

        if ($request->has('risk_class')) {
            $query->where('risk_class', $request->input('risk_class'));
        }


        // Filters based on the related DebtorProfile's billing_model
        if ($request->filled('model') && $request->input('model') !== 'all') {
            $model = $request->input('model');

            if ($model === 'legacy') {
                // Legacy = explicitly 'legacy' OR has no profile at all
                $query->where(function ($q) {
                    $q->doesntHave('debtorProfile')
                        ->orWhereHas('debtorProfile', function ($p) {
                            $p->where('billing_model', 'legacy');
                        });
                });
            } else {
                // Flywheel or Recovery
                $query->whereHas('debtorProfile', function ($p) use ($model) {
                    $p->where('billing_model', $model);
                });
            }
        }

        // Search Filter
        // Searches Debtor fields + Profile Masked IBAN
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('iban', 'like', "%{$search}%") // Raw IBAN on Debtor table
                    ->orWhereHas('debtorProfile', function ($p) use ($search) {
                        $p->where('iban_masked', 'like', "%{$search}%");
                    });
            });
        }

        $debtors = $query->latest('id')->paginate($request->input('per_page', 50));

        return DebtorResource::collection($debtors);
    }

    public function show(Debtor $debtor): DebtorResource
    {
        $debtor->load([
            'upload',
            'debtorProfile',
            'vopLogs.bankReference',
            'billingAttempts',
            'latestVopLog.bankReference',
            'latestBillingAttempt.empAccount',
        ]);

        return new DebtorResource($debtor);
    }

    public function update(Request $request, Debtor $debtor): DebtorResource
    {
        // Handle Raw Data (Only if present in request)
        if ($request->has('raw_data')) {
            $rawData = $request->input('raw_data');
            $debtor->raw_data = $rawData;

            $debtorFields = [];
            foreach ($rawData as $key => $value) {
                $normalizedKey = strtolower(str_replace([' ', '-'], '_', $key));
                if (isset(self::FIELD_MAP[$normalizedKey])) {
                    $field = self::FIELD_MAP[$normalizedKey];
                    $debtorFields[$field] = $this->castValue($field, $value);
                }
            }

            if (isset($debtorFields['iban'])) {
                $debtorFields['iban'] = $this->ibanValidator->normalize($debtorFields['iban']);
                $debtorFields['iban_hash'] = $this->ibanValidator->hash($debtorFields['iban']);
            }

            $debtor->fill($debtorFields);
        }

        // Handle Billing Model Update
        if ($request->filled('model')) {
            $newModel = $request->input('model');

            // Switching TO Legacy -> Delete the profile completely
            if ($newModel === DebtorProfile::MODEL_LEGACY) {
                if ($debtor->debtorProfile) {
                    $debtor->debtorProfile->delete(); // Delete the record
                }
                $debtor->debtorProfile()->dissociate();
            }
            // Switching TO Flywheel or Recovery
            elseif (in_array($newModel, DebtorProfile::BILLING_MODELS)) {
                $profile = $debtor->debtorProfile;

                if ($profile) {
                    // Update existing profile if model changed
                    if ($profile->billing_model !== $newModel) {

                        $newNextBillAt = null; // Default to NULL (Bill Immediately)

                        // If they have paid before, try to preserve the cycle
                        if ($profile->last_success_at) {
                            $calculatedDate = match ($newModel) {
                                DebtorProfile::MODEL_FLYWHEEL => $profile->last_success_at->copy()->addDays(90),
                                DebtorProfile::MODEL_RECOVERY => $profile->last_success_at->copy()->addMonths(6),
                                default => null,
                            };

                            // Only set a specific future date if it hasn't passed yet.
                            // If calculated date is in the past, we leave it as NULL to trigger immediate billing.
                            if ($calculatedDate && $calculatedDate->isFuture()) {
                                $newNextBillAt = $calculatedDate;
                            }
                        }

                        $profile->update([
                            'billing_model' => $newModel,
                            'next_bill_at' => $newNextBillAt
                        ]);
                    }
                } else {
                    // Create new profile logic
                    if ($debtor->iban_hash) {
                        $profile = DebtorProfile::firstOrCreate(
                            ['iban_hash' => $debtor->iban_hash],
                            [
                                'iban_masked' => $debtor->iban,
                                'currency' => $debtor->currency ?? 'EUR',
                                'billing_model' => $newModel,
                                'next_bill_at' => null
                            ]
                        );
                        $debtor->debtorProfile()->associate($profile);
                    }
                }
            }
        }

        // Handle Generic Fields (e.g. email, status)
        $directFields = ['email', 'status', 'risk_class'];
        foreach ($directFields as $field) {
            if ($request->has($field)) {
                $debtor->{$field} = $request->input($field);
            }
        }

        $debtor->save();

        // Validate and Refresh
        $this->validationService->validateAndUpdate($debtor);

        $debtor->load(['upload', 'debtorProfile', 'latestVopLog.bankReference', 'latestBillingAttempt.empAccount']);

        return new DebtorResource($debtor);
    }

    public function validate(Debtor $debtor): JsonResponse
    {
        $this->validationService->validateAndUpdate($debtor);

        return response()->json([
            'message' => 'Validation completed',
            'data' => [
                'id' => $debtor->id,
                'validation_status' => $debtor->validation_status,
                'validation_errors' => $debtor->validation_errors,
                'validated_at' => $debtor->validated_at?->toISOString(),
            ],
        ]);
    }

    public function destroy(Debtor $debtor): JsonResponse
    {
        $debtor->delete();

        return response()->json([
            'message' => 'Debtor deleted successfully',
        ]);
    }

    private function castValue(string $field, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'amount' => (float) str_replace([',', ' '], ['.', ''], $value),
            'country', 'currency' => strtoupper(trim($value)),
            default => trim($value),
        };
    }


    /**
     * Get the count of debtors attached to non-existent (or soft-deleted) uploads.
     */
    public function getOrphanedCount(): JsonResponse
    {
        $count = Debtor::whereNotNull('upload_id')
                        ->doesntHave('upload')
                        ->count();

        return response()->json([
            'orphaned_count' => $count,
        ]);
    }

    /**
     * Remove all debtors that are attached to non-existent (or soft-deleted) uploads.
     *
     * @return JsonResponse
     */
    public function pruneOrphans(): JsonResponse
    {
        $count = Debtor::whereNotNull('upload_id')
                        ->doesntHave('upload')
                        ->delete();

        return response()->json([
            'message' => 'Orphaned debtors cleaned up successfully.',
            'deleted_count' => $count,
        ]);
    }
}
