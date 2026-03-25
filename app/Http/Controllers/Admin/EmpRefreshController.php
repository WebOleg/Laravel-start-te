<?php

/**
 * Controller for EMP Refresh (inbound sync) functionality.
 * Handles async job dispatching and progress tracking.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EmpRefreshByDateJob;
use App\Models\EmpAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class EmpRefreshController extends Controller
{
    /**
     * Start an EMP refresh job.
     *
     * @OA\Post(
     *     path="/api/admin/emp/refresh",
     *     summary="Start EMP inbound sync refresh",
     *     description="Dispatches an async job to sync transaction data from EMP for a given date range. Can target a single EMP account or all accounts. Only one refresh can run at a time. Date range is limited to 90 days.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"from", "to"},
     *             @OA\Property(property="from", type="string", format="date", description="Start date (Y-m-d)", example="2025-03-01"),
     *             @OA\Property(property="to", type="string", format="date", description="End date (Y-m-d), must be >= from", example="2025-03-15"),
     *             @OA\Property(property="emp_account_id", type="integer", nullable=true, description="Specific EMP account to refresh (null = all accounts)", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=202,
     *         description="Refresh job started",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Refresh job started"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="job_id", type="string", format="uuid", example="9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"),
     *                 @OA\Property(property="from", type="string", format="date", example="2025-03-01"),
     *                 @OA\Property(property="to", type="string", format="date", example="2025-03-15"),
     *                 @OA\Property(property="accounts_count", type="integer", example=2),
     *                 @OA\Property(property="estimated_pages", type="integer", example=0),
     *                 @OA\Property(property="queued", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Refresh already in progress",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Refresh already in progress"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="job_id", type="string", format="uuid"),
     *                 @OA\Property(property="started_at", type="string", format="date-time"),
     *                 @OA\Property(property="queued", type="boolean", example=false),
     *                 @OA\Property(property="duplicate", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error (date range exceeds 90 days, no accounts configured)",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Date range cannot exceed 90 days")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
            'emp_account_id' => 'nullable|integer|exists:emp_accounts,id',
        ]);

        $from = \Carbon\Carbon::parse($validated['from']);
        $to = \Carbon\Carbon::parse($validated['to']);

        if ($from->diffInDays($to) > 90) {
            return response()->json([
                'message' => 'Date range cannot exceed 90 days',
                'data' => null,
            ], 422);
        }

        $existingJob = Cache::get('emp_refresh_active');
        if ($existingJob && $existingJob['status'] === 'processing') {
            $jobStatus = Cache::get("emp_refresh_{$existingJob['job_id']}");
            if ($jobStatus || $this->isJobPending($existingJob)) {
                return response()->json([
                    'message' => 'Refresh already in progress',
                    'data' => [
                        'job_id' => $existingJob['job_id'],
                        'started_at' => $existingJob['started_at'],
                        'queued' => false,
                        'duplicate' => true,
                    ],
                ], 409);
            }
            Cache::forget('emp_refresh_active');
        }

        $accountIds = [];
        if (isset($validated['emp_account_id'])) {
            $accountIds = [$validated['emp_account_id']];
        } else {
            $accountIds = EmpAccount::pluck('id')->toArray();

            if (empty($accountIds)) {
                return response()->json([
                    'message' => 'No EMP accounts configured',
                    'data' => null,
                ], 422);
            }
        }

        $jobId = Str::uuid()->toString();

        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => $validated['from'],
            'to' => $validated['to'],
            'account_ids' => $accountIds,
        ], 7200);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'stats' => [
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'errors' => 0,
            ],
            'per_account' => [],
            'duration_seconds' => 0,
            'accounts_total' => count($accountIds),
            'accounts_processed' => 0,
            'current_account' => null,
            'started_at' => now()->toIso8601String(),
        ], 7200);

        EmpRefreshByDateJob::dispatch(
            $validated['from'],
            $validated['to'],
            $jobId,
            $accountIds
        );

        return response()->json([
            'message' => 'Refresh job started',
            'data' => [
                'job_id' => $jobId,
                'from' => $validated['from'],
                'to' => $validated['to'],
                'accounts_count' => count($accountIds),
                'estimated_pages' => 0,
                'queued' => true,
            ],
        ], 202);
    }

    /**
     * Get status of a specific refresh job.
     *
     * @OA\Get(
     *     path="/api/admin/emp/refresh/{jobId}",
     *     summary="Get EMP refresh job status",
     *     description="Returns the current status and progress of a specific EMP refresh job by its UUID. Includes sync stats (inserted, updated, unchanged, errors), per-account breakdown and duration.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="jobId", in="path", required=true, description="Refresh job UUID", @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(
     *         response=200,
     *         description="Job status",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="job_id", type="string", format="uuid"),
     *                 @OA\Property(property="status", type="string", enum={"pending", "processing", "completed", "completed_with_errors", "failed"}, example="processing"),
     *                 @OA\Property(property="progress", type="integer", minimum=0, maximum=100, example=45),
     *                 @OA\Property(property="stats", type="object", nullable=true,
     *                     @OA\Property(property="inserted", type="integer", example=120),
     *                     @OA\Property(property="updated", type="integer", example=35),
     *                     @OA\Property(property="unchanged", type="integer", example=800),
     *                     @OA\Property(property="errors", type="integer", example=2)
     *                 ),
     *                 @OA\Property(property="per_account", type="object", nullable=true),
     *                 @OA\Property(property="duration_seconds", type="integer", nullable=true, example=154),
     *                 @OA\Property(property="accounts_total", type="integer", example=2),
     *                 @OA\Property(property="accounts_processed", type="integer", example=1),
     *                 @OA\Property(property="current_account", type="string", nullable=true, example="Primary Account"),
     *                 @OA\Property(property="started_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="completed_at", type="string", format="date-time", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function status(string $jobId): JsonResponse
    {
        $status = Cache::get("emp_refresh_{$jobId}");

        if (!$status) {
            $active = Cache::get('emp_refresh_active');
            if ($active && $active['job_id'] === $jobId) {
                return response()->json([
                    'message' => 'Job is pending',
                    'data' => [
                        'job_id' => $jobId,
                        'status' => 'pending',
                        'progress' => 0,
                        'stats' => [
                            'inserted' => 0,
                            'updated' => 0,
                            'unchanged' => 0,
                            'errors' => 0,
                        ],
                        'per_account' => [],
                        'duration_seconds' => 0,
                        'accounts_total' => $active['accounts_total'] ?? 0,
                        'accounts_processed' => 0,
                        'started_at' => $active['started_at'] ?? null,
                        'completed_at' => null,
                    ],
                ]);
            }

            return response()->json([
                'message' => 'Job completed or expired',
                'data' => [
                    'job_id' => $jobId,
                    'status' => 'completed',
                    'progress' => 100,
                    'stats' => null,
                    'per_account' => null,
                    'duration_seconds' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'job_id' => $jobId,
                'status' => $status['status'] ?? 'unknown',
                'progress' => $status['progress'] ?? 0,
                'stats' => $status['stats'] ?? [
                    'inserted' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'errors' => 0,
                ],
                'per_account' => $status['per_account'] ?? [],
                'duration_seconds' => $status['duration_seconds'] ?? 0,
                'accounts_total' => $status['accounts_total'] ?? 0,
                'accounts_processed' => $status['accounts_processed'] ?? 0,
                'current_account' => $status['current_account'] ?? null,
                'started_at' => $status['started_at'] ?? null,
                'completed_at' => $status['completed_at'] ?? null,
            ],
        ]);
    }

    /**
     * Get current refresh status.
     *
     * @OA\Get(
     *     path="/api/admin/emp/refresh/status",
     *     summary="Get current EMP refresh status",
     *     description="Returns whether an EMP refresh is currently running, along with its progress and stats. Use this for polling the UI refresh indicator.",
     *     tags={"EMP"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Current refresh status",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="is_processing", type="boolean", example=true),
     *                 @OA\Property(property="job_id", type="string", format="uuid", nullable=true, example="9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d"),
     *                 @OA\Property(property="progress", type="integer", minimum=0, maximum=100, example=45),
     *                 @OA\Property(property="stats", type="object", nullable=true,
     *                     @OA\Property(property="inserted", type="integer", example=120),
     *                     @OA\Property(property="updated", type="integer", example=35),
     *                     @OA\Property(property="unchanged", type="integer", example=800),
     *                     @OA\Property(property="errors", type="integer", example=2)
     *                 ),
     *                 @OA\Property(property="per_account", type="object", nullable=true),
     *                 @OA\Property(property="duration_seconds", type="integer", nullable=true, example=154),
     *                 @OA\Property(property="accounts_total", type="integer", example=2),
     *                 @OA\Property(property="accounts_processed", type="integer", example=1),
     *                 @OA\Property(property="current_account", type="string", nullable=true, example="Primary Account")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function currentStatus(): JsonResponse
    {
        $active = Cache::get('emp_refresh_active');

        if (!$active) {
            return response()->json([
                'data' => [
                    'is_processing' => false,
                    'job_id' => null,
                    'progress' => 0,
                    'stats' => null,
                ],
            ]);
        }

        $jobStatus = Cache::get("emp_refresh_{$active['job_id']}");

        if (!$jobStatus) {
            if ($this->isJobPending($active)) {
                return response()->json([
                    'data' => [
                        'is_processing' => true,
                        'job_id' => $active['job_id'],
                        'progress' => 0,
                        'stats' => [
                            'inserted' => 0,
                            'updated' => 0,
                            'unchanged' => 0,
                            'errors' => 0,
                        ],
                        'per_account' => [],
                        'duration_seconds' => 0,
                        'accounts_total' => $active['accounts_total'] ?? 0,
                        'accounts_processed' => 0,
                    ],
                ]);
            }

            Cache::forget('emp_refresh_active');
            return response()->json([
                'data' => [
                    'is_processing' => false,
                    'job_id' => $active['job_id'],
                    'progress' => 100,
                    'stats' => null,
                ],
            ]);
        }

        if (in_array($jobStatus['status'] ?? '', ['completed', 'completed_with_errors', 'failed'])) {
            Cache::forget('emp_refresh_active');
            return response()->json([
                'data' => [
                    'is_processing' => false,
                    'job_id' => $active['job_id'],
                    'progress' => 100,
                    'stats' => $jobStatus['stats'] ?? null,
                    'per_account' => $jobStatus['per_account'] ?? [],
                    'duration_seconds' => $jobStatus['duration_seconds'] ?? 0,
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'is_processing' => true,
                'job_id' => $active['job_id'],
                'progress' => $jobStatus['progress'] ?? 0,
                'stats' => $jobStatus['stats'] ?? null,
                'per_account' => $jobStatus['per_account'] ?? [],
                'duration_seconds' => $jobStatus['duration_seconds'] ?? 0,
                'accounts_total' => $jobStatus['accounts_total'] ?? 0,
                'accounts_processed' => $jobStatus['accounts_processed'] ?? 0,
                'current_account' => $jobStatus['current_account'] ?? null,
            ],
        ]);
    }

    private function isJobPending(array $active): bool
    {
        $startedAt = \Carbon\Carbon::parse($active['started_at'] ?? now());
        return $startedAt->diffInMinutes(now()) < 5;
    }
}
