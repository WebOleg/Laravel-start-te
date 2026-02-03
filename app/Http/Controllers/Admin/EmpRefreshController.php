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

class EmpRefreshController extends Controller
{
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

        // Determine which accounts to refresh
        $accountIds = [];
        if (isset($validated['emp_account_id'])) {
            // Single account refresh
            $accountIds = [$validated['emp_account_id']];
        } else {
            // All accounts refresh
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
                'accounts_total' => $status['accounts_total'] ?? 0,
                'accounts_processed' => $status['accounts_processed'] ?? 0,
                'current_account' => $status['current_account'] ?? null,
                'started_at' => $status['started_at'] ?? null,
                'completed_at' => $status['completed_at'] ?? null,
            ],
        ]);
    }

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
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'is_processing' => true,
                'job_id' => $active['job_id'],
                'progress' => $jobStatus['progress'] ?? 0,
                'stats' => $jobStatus['stats'] ?? null,
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
