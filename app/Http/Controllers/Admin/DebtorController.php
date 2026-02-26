<?php

/**
 * Admin controller for debtor management.
 * Handles CRUD, validation, orphan cleanup, and bulk reassignment.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtorResource;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Services\DebtorValidationService;
use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
            'empAccount',
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

        if ($request->filled('model') && $request->input('model') !== 'all') {
            $model = $request->input('model');

            if ($model === 'legacy') {
                $query->where(function ($q) {
                    $q->doesntHave('debtorProfile')
                        ->orWhereHas('debtorProfile', function ($p) {
                            $p->where('billing_model', 'legacy');
                        });
                });
            } else {
                $query->whereHas('debtorProfile', function ($p) use ($model) {
                    $p->where('billing_model', $model);
                });
            }
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('iban', 'like', "%{$search}%")
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
            'empAccount',
            'vopLogs.bankReference',
            'billingAttempts',
            'latestVopLog.bankReference',
            'latestBillingAttempt.empAccount',
        ]);

        return new DebtorResource($debtor);
    }

    public function update(Request $request, Debtor $debtor): DebtorResource
    {
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

        if ($request->filled('model')) {
            $newModel = $request->input('model');

            if ($newModel === DebtorProfile::MODEL_LEGACY) {
                if ($debtor->debtorProfile) {
                    $debtor->debtorProfile->delete();
                }
                $debtor->debtorProfile()->dissociate();
            } elseif (in_array($newModel, DebtorProfile::BILLING_MODELS)) {
                $profile = $debtor->debtorProfile;

                if ($profile) {
                    if ($profile->billing_model !== $newModel) {
                        $newNextBillAt = null;

                        if ($profile->last_success_at) {
                            $calculatedDate = match ($newModel) {
                                DebtorProfile::MODEL_FLYWHEEL => $profile->last_success_at->copy()->addDays(90),
                                DebtorProfile::MODEL_RECOVERY => $profile->last_success_at->copy()->addMonths(6),
                                default => null,
                            };

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

        $directFields = ['email', 'status', 'risk_class'];
        foreach ($directFields as $field) {
            if ($request->has($field)) {
                $debtor->{$field} = $request->input($field);
            }
        }

        $debtor->save();

        $this->validationService->validateAndUpdate($debtor);

        $debtor->load(['upload', 'debtorProfile', 'empAccount', 'latestVopLog.bankReference', 'latestBillingAttempt.empAccount']);

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

    /**
     * Bulk reassign debtors to a different EMP account.
     * Updates debtors and their unsent pending billing attempts.
     * Does NOT touch attempts already submitted to EMP (have unique_id)
     * or approved/chargebacked attempts (immutable).
     */
    public function bulkReassign(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_ids' => 'required|array|min:1|max:1000',
            'debtor_ids.*' => 'integer|exists:debtors,id',
            'emp_account_id' => 'required|integer|exists:emp_accounts,id',
        ]);

        $debtorIds = $validated['debtor_ids'];
        $targetAccountId = $validated['emp_account_id'];

        $targetAccount = EmpAccount::findOrFail($targetAccountId);

        if (!$targetAccount->is_active) {
            return response()->json([
                'message' => 'Target EMP account is not active.',
            ], 422);
        }

        Log::info('Bulk reassign started', [
            'target_account_id' => $targetAccountId,
            'target_account_name' => $targetAccount->name,
            'debtor_count' => count($debtorIds),
            'admin_id' => $request->user()?->id,
        ]);

        try {
            $result = DB::transaction(function () use ($debtorIds, $targetAccountId) {
                $debtorsUpdated = Debtor::whereIn('id', $debtorIds)
                    ->where(function ($q) use ($targetAccountId) {
                        $q->where('emp_account_id', '!=', $targetAccountId)
                            ->orWhereNull('emp_account_id');
                    })
                    ->update(['emp_account_id' => $targetAccountId]);

                $pendingBillingUpdated = DB::table('billing_attempts')
                    ->whereIn('debtor_id', $debtorIds)
                    ->where('status', 'pending')
                    ->whereNull('unique_id')
                    ->where(function ($q) use ($targetAccountId) {
                        $q->where('emp_account_id', '!=', $targetAccountId)
                            ->orWhereNull('emp_account_id');
                    })
                    ->update(['emp_account_id' => $targetAccountId]);

                $skippedSubmitted = DB::table('billing_attempts')
                    ->whereIn('debtor_id', $debtorIds)
                    ->where('status', 'pending')
                    ->whereNotNull('unique_id')
                    ->count();

                return [
                    'debtors_updated' => $debtorsUpdated,
                    'pending_billing_updated' => $pendingBillingUpdated,
                    'skipped_submitted' => $skippedSubmitted,
                ];
            });

            Log::info('Bulk reassign completed', [
                'target_account_id' => $targetAccountId,
                'target_account_name' => $targetAccount->name,
                'debtors_updated' => $result['debtors_updated'],
                'pending_billing_updated' => $result['pending_billing_updated'],
                'skipped_submitted' => $result['skipped_submitted'],
                'total_requested' => count($debtorIds),
                'admin_id' => $request->user()?->id,
            ]);

            $message = "Reassigned {$result['debtors_updated']} debtors to {$targetAccount->name}.";
            if ($result['skipped_submitted'] > 0) {
                $message .= " {$result['skipped_submitted']} pending attempts already submitted to EMP were left unchanged.";
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    'debtors_updated' => $result['debtors_updated'],
                    'pending_billing_updated' => $result['pending_billing_updated'],
                    'skipped_submitted' => $result['skipped_submitted'],
                    'target_account' => [
                        'id' => $targetAccount->id,
                        'name' => $targetAccount->name,
                        'slug' => $targetAccount->slug,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk reassign failed', [
                'target_account_id' => $targetAccountId,
                'debtor_count' => count($debtorIds),
                'error' => $e->getMessage(),
                'admin_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Bulk reassign failed. No changes were made.',
                'error' => $e->getMessage(),
            ], 500);
        }
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
