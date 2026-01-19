<?php

/**
 * Admin controller for billing attempts management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingAttemptResource;
use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use App\Services\Emp\EmpBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingAttemptController extends Controller
{
    /**
     * List billing attempts with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BillingAttempt::with(['debtor.debtorProfile', 'upload']);

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('debtor_id')) {
            $query->where('debtor_id', $request->input('debtor_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('model') && $request->input('model') !== 'all') {
            $model = $request->input('model');

            if ($model === DebtorProfile::MODEL_LEGACY) {
                $query->where(function ($q) {
                    $q->where('billing_model', DebtorProfile::MODEL_LEGACY)
                        ->orWhereNull('debtor_profile_id');
                });
            } else {
                $query->where('billing_model', $model);
            }
        }

        // Transaction ID, Unique ID, or Debtor Name/IBAN/Email
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhere('unique_id', 'like', "%{$search}%")

                    ->orWhereHas('debtor', function ($d) use ($search) {
                        $d->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('iban', 'like', "%{$search}%")

                            ->orWhereHas('debtorProfile', function ($dp) use ($search) {
                                $dp->where('iban_masked', 'like', "%{$search}%");
                            });
                    });
            });
        }

        $billingAttempts = $query->latest()->paginate($request->input('per_page', 20));

        return BillingAttemptResource::collection($billingAttempts);
    }

    /**
     * Get single billing attempt.
     */
    public function show(BillingAttempt $billingAttempt): BillingAttemptResource
    {
        $billingAttempt->load(['debtor', 'upload']);

        return new BillingAttemptResource($billingAttempt);
    }

    /**
     * Retry failed billing attempt.
     */
    public function retry(BillingAttempt $billingAttempt, EmpBillingService $billingService): JsonResponse
    {
        if (!$billingAttempt->canRetry()) {
            return response()->json([
                'message' => 'This billing attempt cannot be retried',
                'data' => [
                    'id' => $billingAttempt->id,
                    'status' => $billingAttempt->status,
                    'can_retry' => false,
                ],
            ], 422);
        }

        try {
            $newAttempt = $billingService->retry($billingAttempt);

            return response()->json([
                'message' => 'Retry initiated successfully',
                'data' => new BillingAttemptResource($newAttempt),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Retry failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
