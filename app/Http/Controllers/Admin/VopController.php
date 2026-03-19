<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVopJob;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Annotations as OA;

class VopController extends Controller
{
    public function __construct(
        private VopVerificationService $vopService
    ) {}

    /**
     * Get VOP verification stats for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/vop-stats",
     *     summary="Get VOP verification stats for an upload",
     *     description="Returns VOP verification statistics for an upload including total eligible debtors, verified count, pending count, breakdown by result, average score, and processing status. Supports filtering by billing model.",
     *     tags={"VOP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_type", in="query", required=false, description="Filter by billing model", @OA\Schema(type="string", enum={"all", "legacy", "flywheel", "recovery"})),
     *     @OA\Response(
     *         response=200,
     *         description="VOP stats",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="total_eligible", type="integer", example=900),
     *                 @OA\Property(property="verified", type="integer", example=850),
     *                 @OA\Property(property="pending", type="integer", example=50),
     *                 @OA\Property(property="by_result", type="object", example={"verified": 600, "likely_verified": 150, "inconclusive": 50, "mismatch": 30, "rejected": 20}),
     *                 @OA\Property(property="avg_score", type="integer", example=75),
     *                 @OA\Property(property="is_processing", type="boolean", example=false),
     *                 @OA\Property(property="vop_status", type="string", enum={"idle", "processing", "completed", "failed"}, example="completed"),
     *                 @OA\Property(property="vop_started_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="vop_completed_at", type="string", format="date-time", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function stats(Upload $upload, Request $request): JsonResponse
    {
        $debtorType = $request->input('debtor_type');

        $stats = $this->vopService->getUploadStats($upload->id, $debtorType);

        return response()->json(['data' => $stats]);
    }


    /**
     * Start VOP verification for an upload.
     *
     * @OA\Post(
     *     path="/api/admin/uploads/{upload}/verify-vop",
     *     summary="Start VOP verification for an upload",
     *     description="Dispatches a VOP verification job for all eligible debtors in the upload. Uses IBAN-based deduplication to avoid redundant API calls. Optionally force-refreshes all verifications.",
     *     tags={"VOP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="force", type="boolean", default=false, description="Force re-verification of all debtors, ignoring cache")
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="VOP verification started",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="VOP verification started"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="force_refresh", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="VOP verification already in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="VOP verification already in progress"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="upload_id", type="integer", example=1),
     *                 @OA\Property(property="queued", type="boolean", example=true),
     *                 @OA\Property(property="duplicate", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function verify(Upload $upload, Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('force', false);

        if ($upload->isVopProcessing() && !$forceRefresh) {
            return response()->json([
                'message' => 'VOP verification already in progress',
                'data' => [
                    'upload_id' => $upload->id,
                    'queued' => true,
                    'duplicate' => true,
                ],
            ], 409);
        }

        ProcessVopJob::dispatch($upload, $forceRefresh);

        return response()->json([
            'message' => 'VOP verification started',
            'data' => [
                'upload_id' => $upload->id,
                'force_refresh' => $forceRefresh,
            ],
        ], 202);
    }


    /**
     * Verify a single IBAN via BAV API.
     *
     * @OA\Post(
     *     path="/api/admin/vop/verify-single",
     *     summary="Verify a single IBAN via BAV API",
     *     description="Performs a single BAV (Bank Account Verification) check for an IBAN and name pair. Returns name match result, BIC, and VOP score. Can use mock mode for testing without consuming credits.",
     *     tags={"VOP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"iban", "name"},
     *             @OA\Property(property="iban", type="string", example="DE89370400440532013000"),
     *             @OA\Property(property="name", type="string", example="Hans Mueller"),
     *             @OA\Property(property="use_mock", type="boolean", default=true, description="Use mock response instead of real API")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="BAV verification result",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="valid", type="boolean", example=true),
     *                 @OA\Property(property="name_match", type="string", enum={"yes", "partial", "no", "unavailable"}, example="yes"),
     *                 @OA\Property(property="bic", type="string", nullable=true, example="COBADEFFXXX"),
     *                 @OA\Property(property="vop_score", type="integer", example=100),
     *                 @OA\Property(property="vop_result", type="string", enum={"verified", "likely_verified", "inconclusive", "mismatch", "rejected"}, example="verified"),
     *                 @OA\Property(property="error", type="string", nullable=true, example=null)
     *             ),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="mock_mode", type="boolean", example=true),
     *                 @OA\Property(property="credits_used", type="integer", example=0)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function verifySingle(Request $request): JsonResponse
    {
        $request->validate([
            'iban' => 'required|string',
            'name' => 'required|string',
            'use_mock' => 'boolean',
        ]);

        $useMock = $request->boolean('use_mock', true);

        config(['services.iban.mock' => $useMock]);

        $bavService = app(\App\Services\IbanBavService::class);
        $result = $bavService->verify($request->iban, $request->name);

        return response()->json([
            'data' => $result,
            'meta' => [
                'mock_mode' => $useMock,
                'credits_used' => $useMock ? 0 : 1,
            ],
        ]);
    }

    /**
     * Get VOP logs for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/uploads/{upload}/vop-logs",
     *     summary="Get VOP logs for an upload",
     *     description="Returns a paginated list of VOP verification logs for the upload, with debtor name and IBAN. Ordered by most recent first.",
     *     tags={"VOP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated VOP logs",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="debtor_id", type="integer", example=42),
     *                     @OA\Property(property="upload_id", type="integer", example=1),
     *                     @OA\Property(property="iban_masked", type="string", example="DE89****3000"),
     *                     @OA\Property(property="iban_valid", type="boolean", example=true),
     *                     @OA\Property(property="bank_identified", type="boolean", example=true),
     *                     @OA\Property(property="bank_name", type="string", nullable=true, example="Deutsche Bank"),
     *                     @OA\Property(property="bic", type="string", nullable=true, example="DEUTDEFF"),
     *                     @OA\Property(property="country", type="string", nullable=true, example="DE"),
     *                     @OA\Property(property="vop_score", type="integer", example=85),
     *                     @OA\Property(property="result", type="string", enum={"verified", "likely_verified", "inconclusive", "mismatch", "rejected"}, example="verified"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="debtor", type="object", nullable=true,
     *                         @OA\Property(property="id", type="integer", example=42),
     *                         @OA\Property(property="first_name", type="string", example="Hans"),
     *                         @OA\Property(property="last_name", type="string", example="Mueller"),
     *                         @OA\Property(property="iban", type="string", example="DE89370400440532013000")
     *                     )
     *                 )
     *             ),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=17),
     *                 @OA\Property(property="per_page", type="integer", example=50),
     *                 @OA\Property(property="total", type="integer", example=850)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found")
     * )
     */
    public function logs(Upload $upload): JsonResponse
    {
        $logs = VopLog::where('upload_id', $upload->id)
            ->with('debtor:id,first_name,last_name,iban')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }
}
