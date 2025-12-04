<?php

/**
 * Admin controller for debtor management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebtorResource;
use App\Models\Debtor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DebtorController extends Controller
{
    /**
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Debtor::with(['upload', 'latestVopLog', 'latestBillingAttempt']);

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('country')) {
            $query->where('country', $request->input('country'));
        }

        if ($request->has('risk_class')) {
            $query->where('risk_class', $request->input('risk_class'));
        }

        $debtors = $query->latest()->paginate($request->input('per_page', 20));

        return DebtorResource::collection($debtors);
    }

    /**
     * @return DebtorResource
     */
    public function show(Debtor $debtor): DebtorResource
    {
        $debtor->load([
            'upload',
            'vopLogs',
            'billingAttempts',
            'latestVopLog',
            'latestBillingAttempt',
        ]);

        return new DebtorResource($debtor);
    }
}
