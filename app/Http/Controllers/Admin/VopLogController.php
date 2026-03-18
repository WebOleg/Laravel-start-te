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
use OpenApi\Annotations as OA;

class VopLogController extends Controller
{
    /**
     * List VOP logs with filters.
     *
     * @OA\Get(
     *     path="/api/admin/vop-logs",
     *     summary="List VOP verification logs",
     *     description="Returns a paginated list of VOP verification logs with debtor and upload relations. Supports filtering by upload, debtor, result, BAV verification status, and text search across BIC, masked IBAN, and debtor IBAN.",
     *     tags={"VOP Logs"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload_id", in="query", required=false, description="Filter by upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_id", in="query", required=false, description="Filter by debtor ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="result", in="query", required=false, description="Filter by VOP result", @OA\Schema(type="string", enum={"verified", "likely_verified", "inconclusive", "mismatch", "rejected"})),
     *     @OA\Parameter(name="bav_verified", in="query", required=false, description="Filter by BAV verification status", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="search", in="query", required=false, description="Search across BIC, masked IBAN, and debtor IBAN", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated VOP logs",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/VopLog")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=5),
     *                 @OA\Property(property="per_page", type="integer", example=20),
     *                 @OA\Property(property="total", type="integer", example=100)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = VopLog::with(['debtor', 'upload']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('bic', 'ilike', "%{$search}%")
                  ->orWhere('iban_masked', 'ilike', "%{$search}%")
                  ->orWhereHas('debtor', fn($dq) => $dq->where('iban', 'ilike', "%{$search}%"));
            });
        }

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
     * Get a single VOP log.
     *
     * @OA\Get(
     *     path="/api/admin/vop-logs/{vopLog}",
     *     summary="Get a single VOP log",
     *     description="Returns detailed VOP verification log with debtor and upload relations.",
     *     tags={"VOP Logs"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="vopLog", in="path", required=true, description="VOP Log ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="VOP log details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/VopLog")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="VOP log not found")
     * )
     */
    public function show(VopLog $vopLog): VopLogResource
    {
        $vopLog->load(['debtor', 'upload']);

        return new VopLogResource($vopLog);
    }
}
