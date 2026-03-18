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
use OpenApi\Annotations as OA;

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

    /**
     * List debtors with filters.
     *
     * @OA\Get(
     *     path="/api/admin/debtors",
     *     summary="List debtors",
     *     description="Returns a paginated list of debtors with optional filters by upload, status, validation status, country, risk class, billing model, and free-text search across names, emails, and IBANs.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload_id", in="query", required=false, description="Filter by upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", required=false, description="Filter by debtor status", @OA\Schema(type="string", enum={"uploaded", "pending", "processing", "approved", "chargebacked", "recovered", "failed"})),
     *     @OA\Parameter(name="validation_status", in="query", required=false, description="Filter by validation status", @OA\Schema(type="string", enum={"pending", "valid", "invalid"})),
     *     @OA\Parameter(name="country", in="query", required=false, description="Filter by country code", @OA\Schema(type="string", example="DE")),
     *     @OA\Parameter(name="risk_class", in="query", required=false, description="Filter by risk class", @OA\Schema(type="string", enum={"low", "medium", "high"})),
     *     @OA\Parameter(name="model", in="query", required=false, description="Filter by billing model ('all' returns unfiltered)", @OA\Schema(type="string", enum={"all", "legacy", "flywheel", "recovery"})),
     *     @OA\Parameter(name="search", in="query", required=false, description="Search across first_name, last_name, email, IBAN, iban_masked", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page", @OA\Schema(type="integer", default=50)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of debtors",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Debtor")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=10),
     *                 @OA\Property(property="per_page", type="integer", example=50),
     *                 @OA\Property(property="total", type="integer", example=500)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
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

    /**
     * Get single debtor with all relations.
     *
     * @OA\Get(
     *     path="/api/admin/debtors/{debtor}",
     *     summary="Get a single debtor",
     *     description="Returns detailed debtor information including upload, debtor profile, EMP account, VOP logs, billing attempts, and latest VOP/billing data.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="debtor", in="path", required=true, description="Debtor ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Debtor details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Debtor")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Debtor not found")
     * )
     */
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

    /**
     * Update a debtor.
     *
     * @OA\Put(
     *     path="/api/admin/debtors/{debtor}",
     *     summary="Update a debtor",
     *     description="Updates debtor fields including raw_data (auto-maps to debtor fields via FIELD_MAP), billing model (creates/updates/removes DebtorProfile), email, status, and risk_class. Re-runs validation after update.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="debtor", in="path", required=true, description="Debtor ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="raw_data", type="object", description="Key-value pairs that auto-map to debtor fields (iban, first_name, last_name, email, amount, etc.)", example={"iban": "DE89370400440532013000", "first_name": "Hans", "last_name": "Mueller", "amount": "49.99"}),
     *             @OA\Property(property="model", type="string", description="Change billing model (creates/updates DebtorProfile or removes it for legacy)", enum={"legacy", "flywheel", "recovery"}),
     *             @OA\Property(property="email", type="string", format="email", example="hans@example.com"),
     *             @OA\Property(property="status", type="string", enum={"uploaded", "pending", "processing", "approved", "chargebacked", "recovered", "failed"}),
     *             @OA\Property(property="risk_class", type="string", enum={"low", "medium", "high"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Updated debtor",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Debtor")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Debtor not found")
     * )
     */
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

    /**
     * Validate a single debtor.
     *
     * @OA\Post(
     *     path="/api/admin/debtors/{debtor}/validate",
     *     summary="Validate a debtor",
     *     description="Runs the full validation pipeline on a debtor: required fields, name format, IBAN validity and SEPA check, amount range, email format, country, encoding, BIC resolution, and blacklist check. Updates validation_status and validation_errors.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="debtor", in="path", required=true, description="Debtor ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Validation result",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Validation completed"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=42),
     *                 @OA\Property(property="validation_status", type="string", enum={"valid", "invalid"}, example="valid"),
     *                 @OA\Property(property="validation_errors", type="array", nullable=true, @OA\Items(type="string"), example=null),
     *                 @OA\Property(property="validated_at", type="string", format="date-time", example="2025-03-15T10:30:00+00:00")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Debtor not found")
     * )
     */
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

    /**
     * Delete a debtor.
     *
     * @OA\Delete(
     *     path="/api/admin/debtors/{debtor}",
     *     summary="Delete a debtor",
     *     description="Soft-deletes a debtor record.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="debtor", in="path", required=true, description="Debtor ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Debtor deleted",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Debtor deleted successfully")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Debtor not found")
     * )
     */
    public function destroy(Debtor $debtor): JsonResponse
    {
        $debtor->delete();

        return response()->json([
            'message' => 'Debtor deleted successfully',
        ]);
    }

    /**
     * Bulk reassign debtors to a different EMP account.
     *
     * @OA\Post(
     *     path="/api/admin/debtors/bulk-reassign",
     *     summary="Bulk reassign debtors to a different EMP account",
     *     description="Reassigns debtors and their unsent pending billing attempts (no unique_id) to a target EMP account. Attempts already submitted to EMP (have unique_id) or in final states are left unchanged. Runs in a database transaction.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"debtor_ids", "emp_account_id"},
     *             @OA\Property(property="debtor_ids", type="array", minItems=1, maxItems=1000, @OA\Items(type="integer"), example={1, 2, 3}),
     *             @OA\Property(property="emp_account_id", type="integer", description="Target EMP account ID", example=2)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Reassignment completed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Reassigned 3 debtors to Primary Account. 1 pending attempts already submitted to EMP were left unchanged."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="debtors_updated", type="integer", example=3),
     *                 @OA\Property(property="pending_billing_updated", type="integer", example=2),
     *                 @OA\Property(property="skipped_submitted", type="integer", example=1),
     *                 @OA\Property(property="target_account", type="object",
     *                     @OA\Property(property="id", type="integer", example=2),
     *                     @OA\Property(property="name", type="string", example="Primary Account"),
     *                     @OA\Property(property="slug", type="string", example="primary-account")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Target account is not active or validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Target EMP account is not active.")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=500, description="Transaction failed, no changes made")
     * )
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
     * Get orphaned debtors count.
     *
     * @OA\Get(
     *     path="/api/admin/debtors/orphans/count",
     *     summary="Get count of orphaned debtors",
     *     description="Returns the count of debtors attached to non-existent or soft-deleted uploads.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Orphaned debtor count",
     *         @OA\JsonContent(
     *             @OA\Property(property="orphaned_count", type="integer", example=15)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
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
     * Remove all orphaned debtors.
     *
     * @OA\Delete(
     *     path="/api/admin/debtors/orphans",
     *     summary="Delete all orphaned debtors",
     *     description="Permanently removes all debtors that are attached to non-existent or soft-deleted uploads.",
     *     tags={"Debtors"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Orphaned debtors removed",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Orphaned debtors cleaned up successfully."),
     *             @OA\Property(property="deleted_count", type="integer", example=15)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
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
