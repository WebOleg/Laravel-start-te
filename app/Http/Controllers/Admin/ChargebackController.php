<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ChargebackService;
use App\Http\Resources\ChargebackResource;
use App\Models\Upload;
use OpenApi\Annotations as OA;

class ChargebackController extends Controller
{
    public function __construct(
        protected ChargebackService $chargebackService,
    )
    {}

    /**
     * List chargebacks with filters and stats.
     *
     * @OA\Get(
     *     path="/api/admin/chargebacks",
     *     summary="List chargebacks with statistics",
     *     description="Returns a paginated list of chargebacked billing attempts with debtor and account details. Includes aggregated chargeback statistics (total count, amount, rate, top reason code) in the response meta.",
     *     tags={"Chargebacks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance (takes priority over emp_account_id)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="code", in="query", required=false, description="Filter by chargeback reason code", @OA\Schema(type="string", maxLength=12, example="MD06")),
     *     @OA\Parameter(name="period", in="query", required=false, description="Time period filter", @OA\Schema(type="string", enum={"24h", "7d", "30d", "90d", "all"})),
     *     @OA\Parameter(name="date_mode", in="query", required=false, description="Date field to filter on: transaction date or chargeback date", @OA\Schema(type="string", enum={"transaction", "chargeback"}, default="transaction")),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page (max 100)", @OA\Schema(type="integer", minimum=1, maximum=100, default=50)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated chargebacks with statistics",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Chargeback")),
     *             @OA\Property(property="stats", type="object",
     *                 @OA\Property(property="total_chargebacks_count", type="integer", example=85),
     *                 @OA\Property(property="total_chargeback_amount", type="number", format="float", example=4250.00),
     *                 @OA\Property(property="chargeback_rate", type="number", format="float", example=7.83),
     *                 @OA\Property(property="average_chargeback_amount", type="number", format="float", example=50.00),
     *                 @OA\Property(property="most_common_reason_code", type="object", nullable=true,
     *                     @OA\Property(property="code", type="string", example="MD06"),
     *                     @OA\Property(property="count", type="integer", example=35)
     *                 ),
     *                 @OA\Property(property="affected_accounts", type="integer", example=2),
     *                 @OA\Property(property="unique_debtors_count", type="integer", example=78),
     *                 @OA\Property(property="total_approved_amount", type="number", format="float", example=50000.00)
     *             ),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=2),
     *                 @OA\Property(property="per_page", type="integer", example=50),
     *                 @OA\Property(property="total", type="integer", example=85)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function index(Request $request)
    {
        $request->validate([
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
            'code' => 'nullable|string|max:12',
            'period' => 'nullable|string|in:24h,7d,30d,90d,all',
            'date_mode' => 'nullable|string|in:transaction,chargeback',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $chargebacks = $this->chargebackService->getChargebacks($request);
        $stats = $this->chargebackService->getChargebackStatistics($request);

        return ChargebackResource::collection($chargebacks)->additional([
            'stats' => $stats,
        ]);
    }

    /**
     * Get unique chargeback reason codes.
     *
     * @OA\Get(
     *     path="/api/admin/chargebacks/codes",
     *     summary="Get all unique chargeback reason codes",
     *     description="Returns a list of all distinct chargeback reason codes found in billing attempts. Cached for performance.",
     *     tags={"Chargebacks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of unique reason codes",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(type="string", example="MD06")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function codes(){
        $errorCodes = $this->chargebackService->getUniqueChargebacksErrorCodes();
        return response()->json(['data' => $errorCodes]);
    }

    /**
     * Get chargeback reasons breakdown for an upload.
     *
     * @OA\Get(
     *     path="/api/admin/chargebacks/upload/{upload}",
     *     summary="Get chargeback reason breakdown for an upload",
     *     description="Returns a summary of billing vs chargeback stats for the upload, plus a breakdown of chargeback reason codes with counts, amounts, and percentages. Excludes configured reason codes.",
     *     tags={"Chargebacks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="emp_account_id", in="query", required=false, description="Filter by EMP account", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="tether_instance_id", in="query", required=false, description="Filter by Tether instance", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", required=false, description="Filter chargebacks from date", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, description="Filter chargebacks to date", @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Upload chargeback summary and reason breakdown",
     *         @OA\JsonContent(
     *             @OA\Property(property="summary", type="object",
     *                 @OA\Property(property="total_records", type="integer", example=1000),
     *                 @OA\Property(property="valid_count", type="integer", example=950),
     *                 @OA\Property(property="billed_count", type="integer", example=800),
     *                 @OA\Property(property="total_chargebacks", type="integer", example=45),
     *                 @OA\Property(property="cb_amount", type="number", format="float", example=2250.00),
     *                 @OA\Property(property="approved_amount", type="number", format="float", example=40000.00),
     *                 @OA\Property(property="cb_rate", type="number", format="float", example=5.33)
     *             ),
     *             @OA\Property(property="reasons", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="code", type="string", example="MD06"),
     *                     @OA\Property(property="reason", type="string", nullable=true, example="Refund request by end customer"),
     *                     @OA\Property(property="cb_count", type="integer", example=20),
     *                     @OA\Property(property="cb_amount", type="number", format="float", example=1000.00),
     *                     @OA\Property(property="cb_percentage", type="number", format="float", example=44.44),
     *                     @OA\Property(property="total_percentage", type="number", format="float", example=2.0),
     *                     @OA\Property(property="last_occurrence", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="total_records", type="integer", example=1000)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function uploadReasons(Request $request, Upload $upload)
    {
        $request->validate([
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
            'tether_instance_id' => 'nullable|integer|exists:tether_instances,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:date_from',
        ]);

        $data = $this->chargebackService->getUploadChargebackReasons(
            $upload,
            $request->only(['emp_account_id', 'tether_instance_id', 'start_date', 'end_date'])
        );

        return response()->json($data);
    }

    /**
     * Get chargeback records for a specific reason code within an upload.
     *
     * @OA\Get(
     *     path="/api/admin/chargebacks/upload/{upload}/{code}/records",
     *     summary="Get chargeback records by reason code for an upload",
     *     description="Returns a paginated list of chargebacked billing attempts for a specific upload and reason code. Includes debtor details and EMP account info.",
     *     tags={"Chargebacks"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload", in="path", required=true, description="Upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="code", in="path", required=true, description="Chargeback reason code (use 'null' for records without a code)", @OA\Schema(type="string", example="MD06")),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page (max 100)", @OA\Schema(type="integer", minimum=1, maximum=100, default=100)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated chargeback records",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Chargeback")),
     *             @OA\Property(property="links", type="object"),
     *             @OA\Property(property="meta", type="object",
     *                 @OA\Property(property="current_page", type="integer", example=1),
     *                 @OA\Property(property="last_page", type="integer", example=1),
     *                 @OA\Property(property="per_page", type="integer", example=100),
     *                 @OA\Property(property="total", type="integer", example=20)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Upload not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function uploadReasonRecords(Request $request, Upload $upload, string $code)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $records = $this->chargebackService->getUploadChargebackRecordsByCode(
            $upload,
            $code,
            $request->input('per_page', 100)
        );

        return ChargebackResource::collection($records);
    }
}
