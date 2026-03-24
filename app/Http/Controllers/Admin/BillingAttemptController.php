<?php

/**
 * Admin controller for billing attempts management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BillingAttemptResource;
use App\Jobs\ExportCleanUsersJob;
use App\Models\BillingAttempt;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Services\Emp\EmpBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingAttemptController extends Controller
{
    private const STREAMING_THRESHOLD = 10000;

    /**
     * List billing attempts with filters.
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts",
     *     summary="List billing attempts",
     *     description="Returns a paginated list of billing attempts with optional filters by upload, debtor, status, billing model, and free-text search across transaction IDs, debtor names, emails, and IBANs.",
     *     tags={"Billing Attempts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="upload_id", in="query", required=false, description="Filter by upload ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="debtor_id", in="query", required=false, description="Filter by debtor ID", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", required=false, description="Filter by status", @OA\Schema(type="string", enum={"pending", "approved", "declined", "error", "voided", "chargebacked"})),
     *     @OA\Parameter(name="model", in="query", required=false, description="Filter by billing model ('all' returns unfiltered)", @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", required=false, description="Search across transaction_id, unique_id, debtor name, email, IBAN", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, description="Results per page", @OA\Schema(type="integer", default=20)),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated list of billing attempts",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BillingAttempt")),
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
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts/{billing_attempt}",
     *     summary="Get a single billing attempt",
     *     description="Returns detailed information for a specific billing attempt including related debtor and upload data.",
     *     tags={"Billing Attempts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="billing_attempt", in="path", required=true, description="Billing attempt ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Billing attempt details",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/BillingAttempt")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Billing attempt not found")
     * )
     */
    public function show(BillingAttempt $billingAttempt): BillingAttemptResource
    {
        $billingAttempt->load(['debtor', 'upload']);

        return new BillingAttemptResource($billingAttempt);
    }

    /**
     * Retry failed billing attempt.
     *
     * @OA\Post(
     *     path="/api/admin/billing-attempts/{billing_attempt}/retry",
     *     summary="Retry a failed billing attempt",
     *     description="Creates a new billing attempt by retrying a failed one. Only attempts with status 'declined' or 'error' can be retried.",
     *     tags={"Billing Attempts"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="billing_attempt", in="path", required=true, description="Billing attempt ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=201,
     *         description="Retry initiated, new billing attempt created",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Retry initiated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/BillingAttempt")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Attempt cannot be retried",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="This billing attempt cannot be retried"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="status", type="string", example="approved"),
     *                 @OA\Property(property="can_retry", type="boolean", example=false)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Billing attempt not found"),
     *     @OA\Response(response=500, description="Retry failed due to server error")
     * )
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

    /**
     * Get stats for clean users.
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts/clean-users/stats",
     *     summary="Get clean users stats",
     *     description="Returns the count of 'clean' debtors — those with approved charges, no lifetime chargebacks, and not charged in the last X days. Supports broad (>=1 approved), strict (>=2 approved), and strict3 (>=3 approved) modes.",
     *     tags={"Clean Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="min_days", in="query", required=false, description="Exclude debtors charged in last N days", @OA\Schema(type="integer", minimum=1, maximum=365, default=30)),
     *     @OA\Parameter(name="mode", in="query", required=false, description="Broad: >=1 approved; Strict: >=2 approved with date filter; Strict3: >=3 approved with date filter", @OA\Schema(type="string", enum={"broad", "strict", "strict3"}, default="broad")),
     *     @OA\Parameter(name="account_id", in="query", required=false, description="Filter by EMP account ID", @OA\Schema(type="inter")),
     *     @OA\Response(
     *         response=200,
     *         description="Clean users count and parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="count", type="integer", example=1250),
     *                 @OA\Property(property="min_days", type="integer", example=30),
     *                 @OA\Property(property="mode", type="string", example="broad"),
     *                 @OA\Property(property="account_id", type="integer", nullable=true, example=null),
     *                 @OA\Property(property="streaming_threshold", type="integer", example=10000)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function cleanUsersStats(Request $request): JsonResponse
    {
        $request->validate([
            'min_days' => 'nullable|integer|min:1|max:365',
            'mode' => 'nullable|in:broad,strict,strict3',
            'account_id' => 'nullable|integer',
        ]);

        $minDays = (int) $request->input('min_days', 30);
        $mode = $request->input('mode', 'broad');
        $accountId = $request->input('account_id');

        $count = $this->buildCleanUsersQuery($minDays, $mode, $accountId)->count();

        return response()->json([
            'data' => [
                'count' => $count,
                'min_days' => $minDays,
                'mode' => $mode,
                'account_id' => $accountId,
                'streaming_threshold' => self::STREAMING_THRESHOLD,
            ],
        ]);
    }

    /**
     * Export clean users.
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts/clean-users/export",
     *     summary="Export clean users as CSV",
     *     description="Exports clean users data. For small exports (<=10,000 records) returns a streamed CSV directly. For larger exports, queues a background job and returns a job ID for polling.",
     *     tags={"Clean Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="limit", in="query", required=true, description="Number of records to export", @OA\Schema(type="integer", minimum=1, maximum=100000)),
     *     @OA\Parameter(name="min_days", in="query", required=false, description="Exclude debtors charged in last N days", @OA\Schema(type="integer", minimum=1, maximum=365, default=30)),
     *     @OA\Parameter(name="mode", in="query", required=false, description="Broad: >=1 approved; Strict: >=2 approved with date filter; Strict3: >=3 approved with date filter", @OA\Schema(type="string", enum={"broad", "strict", "strict3"}, default="broad")),
     *     @OA\Parameter(name="account_id", in="query", required=false, description="Filter by EMP account ID", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Streamed CSV file (when limit <= 10,000)",
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Export queued as background job (when limit > 10,000)",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="job_id", type="string", format="uuid", example="9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"),
     *                 @OA\Property(property="status", type="string", example="pending"),
     *                 @OA\Property(property="message", type="string", example="Export queued. Use job_id to check status.")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function exportCleanUsers(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'limit' => 'required|integer|min:1|max:100000',
            'min_days' => 'nullable|integer|min:1|max:365',
            'mode' => 'nullable|in:broad,strict,strict3',
            'account_id' => 'nullable|integer',
        ]);

        $limit = (int) $request->input('limit');
        $minDays = (int) $request->input('min_days', 30);
        $mode = $request->input('mode', 'broad');
        $accountId = $request->input('account_id');

        if ($limit <= self::STREAMING_THRESHOLD) {
            return $this->streamCleanUsers($limit, $minDays, $mode, $accountId);
        }

        return $this->queueCleanUsersExport($limit, $minDays, $mode, $accountId);
    }

    /**
     * Get export job status.
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts/clean-users/export/{jobId}/status",
     *     summary="Get clean users export job status",
     *     description="Returns the status and progress of a queued clean users export job. When completed, includes a download URL.",
     *     tags={"Clean Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="jobId", in="path", required=true, description="Export job UUID", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(
     *         response=200,
     *         description="Export job status",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="status", type="string", enum={"pending", "processing", "completed", "failed"}, example="processing"),
     *                 @OA\Property(property="progress", type="integer", example=45),
     *                 @OA\Property(property="processed", type="integer", example=4500),
     *                 @OA\Property(property="limit", type="integer", example=10000),
     *                 @OA\Property(property="min_days", type="integer", example=30),
     *                 @OA\Property(property="mode", type="string", example="broad"),
     *                 @OA\Property(property="download_url", type="string", nullable=true, example="/api/admin/billing-attempts/clean-users/export/9b1d.../download")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(
     *         response=404,
     *         description="Export job not found",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Export job not found"))
     *     )
     * )
     */
    public function exportStatus(string $jobId): JsonResponse
    {
        $status = Cache::get("clean_users_export:{$jobId}");

        if (!$status) {
            return response()->json([
                'message' => 'Export job not found',
            ], 404);
        }

        if ($status['status'] === 'completed' && isset($status['path'])) {
            $status['download_url'] = route('admin.clean-users.download', ['jobId' => $jobId]);
        }

        return response()->json([
            'data' => $status,
        ]);
    }

    /**
     * Download completed export file.
     *
     * @OA\Get(
     *     path="/api/admin/billing-attempts/clean-users/export/{jobId}/download",
     *     summary="Download clean users export CSV",
     *     description="Downloads the CSV file for a completed clean users export job. Streams the file from S3 storage.",
     *     tags={"Clean Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="jobId", in="path", required=true, description="Export job UUID", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(
     *         response=200,
     *         description="CSV file download",
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Export not ready or file not found",
     *         @OA\JsonContent(@OA\Property(property="message", type="string", example="Export not ready or not found"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function downloadExport(string $jobId): JsonResponse|StreamedResponse
    {
        $status = Cache::get("clean_users_export:{$jobId}");

        if (!$status || $status['status'] !== 'completed') {
            return response()->json([
                'message' => 'Export not ready or not found',
            ], 404);
        }

        $path = $status['path'];

        if (!Storage::disk('s3')->exists($path)) {
            return response()->json([
                'message' => 'Export file not found',
            ], 404);
        }

        $filename = $status['filename'];

        return response()->streamDownload(function () use ($path) {
            $stream = Storage::disk('s3')->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    /**
     * Stream small export directly.
     */
    private function streamCleanUsers(int $limit, int $minDays, string $mode = 'broad', ?int $accountId = null): StreamedResponse
    {
        $filename = 'clean_users_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($limit, $minDays, $mode, $accountId) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['first_name', 'last_name', 'iban', 'bic', 'amount', 'currency']);

            $query = $this->buildCleanUsersQuery($minDays, $mode, $accountId)
                ->with('debtor:id,first_name,last_name,iban,bic')
                ->select('id', 'debtor_id', 'amount', 'currency')
                ->limit($limit);

            foreach ($query->lazy(500) as $attempt) {
                $debtor = $attempt->debtor;
                if (!$debtor || !$debtor->iban) {
                    continue;
                }

                fputcsv($handle, [
                    $debtor->first_name ?? '',
                    $debtor->last_name ?? '',
                    $debtor->iban,
                    $debtor->bic ?? '',
                    number_format($attempt->amount, 2, '.', ''),
                    $attempt->currency ?? 'EUR',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Queue large export as background job.
     */
    private function queueCleanUsersExport(int $limit, int $minDays, string $mode = 'broad', ?int $accountId = null): JsonResponse
    {
        $jobId = Str::uuid()->toString();

        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'processed' => 0,
            'limit' => $limit,
            'min_days' => $minDays,
            'mode' => $mode,
            'account_id' => $accountId,
            'created_at' => now()->toISOString(),
        ], now()->addHours(24));

        ExportCleanUsersJob::dispatch($jobId, $limit, $minDays, $mode, $accountId);

        return response()->json([
            'data' => [
                'job_id' => $jobId,
                'status' => 'pending',
                'message' => 'Export queued. Use job_id to check status.',
            ],
        ], 202);
    }

    /**
     * Build optimized query for clean users.
     * Logic: approved charge + no lifetime CB + not charged in last X days.
     * Broad: >=1 approved charge.
     * Strict: >=2 approved charges lifetime across all uploads.
     * Strict3: >=3 approved charges lifetime across all uploads.
     */
    private function buildCleanUsersQuery(int $minDays, string $mode = 'broad', ?int $accountId = null)
    {
        $chargebackedSubquery = BillingAttempt::select('debtor_id')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNotNull('debtor_id')
            ->distinct();

        // Select the first approved attempt per debtor by lowest id.
        // Using whereIn on MIN(id) instead of attempt_number=1 ensures debtors
        // whose first attempt was an error but later got approved are included.
        $firstApprovedIds = BillingAttempt::selectRaw('MIN(id)')
            ->where('status', BillingAttempt::STATUS_APPROVED)
            ->whereNotNull('debtor_id')
            ->groupBy('debtor_id');

        $query = BillingAttempt::query()
            ->where('status', BillingAttempt::STATUS_APPROVED)
            ->whereIn('id', $firstApprovedIds)
            ->whereNotNull('debtor_id')
            ->whereNotIn('debtor_id', $chargebackedSubquery);

        $recentlyChargedSubquery = BillingAttempt::select('debtor_id')
            ->where('emp_created_at', '>=', now()->subDays($minDays))
            ->whereNotNull('debtor_id')
            ->distinct();

        $query->whereNotIn('debtor_id', $recentlyChargedSubquery);

        if ($accountId) {
            $query->where('emp_account_id', $accountId);
        }

        // Map modes to minimum approved charges required lifetime across all uploads.
        $modeRequirements = [
            'strict' => 2,
            'strict3' => 3,
        ];

        if (isset($modeRequirements[$mode])) {
            $minCount = $modeRequirements[$mode];
            $debtorsWithMultiple = BillingAttempt::select('debtor_id')
                ->where('status', BillingAttempt::STATUS_APPROVED)
                ->whereNotNull('debtor_id')
                ->groupBy('debtor_id')
                ->havingRaw('COUNT(*) >= ?', [$minCount]);

            $query->whereIn('debtor_id', $debtorsWithMultiple);
        }

        return $query->oldest('emp_created_at');
    }
}
