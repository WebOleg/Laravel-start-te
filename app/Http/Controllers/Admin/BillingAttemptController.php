<?php

/**
 * Admin controller for billing attempts management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingAttemptResource;
use App\Models\BillingAttempt;
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
        $query = BillingAttempt::with(['debtor', 'upload']);

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('debtor_id')) {
            $query->where('debtor_id', $request->input('debtor_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
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
