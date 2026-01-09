<?php

/**
 * Admin controller for VOP verification results management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\VopLogResource;
use App\Models\VopLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VopLogController extends Controller
{
    /**
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = VopLog::with(['debtor', 'upload']);

        if ($request->filled('bav_verified')) {
            $query->where('bav_verified', filter_var($request->bav_verified, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->has('upload_id')) {
            $query->where('upload_id', $request->input('upload_id'));
        }

        if ($request->has('result')) {
            $query->where('result', $request->input('result'));
        }

        if ($request->has('debtor_id')) {
            $query->where('debtor_id', $request->input('debtor_id'));
        }

        $vopLogs = $query->latest()->paginate($request->input('per_page', 20));

        return VopLogResource::collection($vopLogs);
    }

    /**
     * @return VopLogResource
     */
    public function show(VopLog $vopLog): VopLogResource
    {
        $vopLog->load(['debtor', 'upload']);

        return new VopLogResource($vopLog);
    }
}
