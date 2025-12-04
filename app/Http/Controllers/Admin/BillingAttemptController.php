<?php

/**
 * Admin controller for billing attempts management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingAttemptResource;
use App\Models\BillingAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BillingAttemptController extends Controller
{
    /**
     * @return AnonymousResourceCollection
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
     * @return BillingAttemptResource
     */
    public function show(BillingAttempt $billingAttempt): BillingAttemptResource
    {
        $billingAttempt->load(['debtor', 'upload']);

        return new BillingAttemptResource($billingAttempt);
    }
}
