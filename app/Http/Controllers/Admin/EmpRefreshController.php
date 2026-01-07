<?php

/**
 * Controller for EMP Refresh (inbound sync) functionality.
 * Handles async job dispatching and progress tracking.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EmpRefreshByDateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EmpRefreshController extends Controller
{
    /**
     * Trigger EMP refresh for a date range.
     *
     * POST /api/admin/emp/refresh
     */
    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date|date_format:Y-m-d',
            'to' => 'required|date|date_format:Y-m-d|after_or_equal:from',
        ]);

        // Validate date range (max 90 days)
        $from = \Carbon\Carbon::parse($validated['from']);
        $to = \Carbon\Carbon::parse($validated['to']);
        
        if ($from->diffInDays($to) > 90) {
            return response()->json([
                'error' => 'Date range cannot exceed 90 days',
            ], 422);
        }

        // Check if refresh is already in progress
        $existingJob = Cache::get('emp_refresh_active');
        if ($existingJob && $existingJob['status'] === 'processing') {
            return response()->json([
                'error' => 'Refresh already in progress',
                'job_id' => $existingJob['job_id'],
                'started_at' => $existingJob['started_at'],
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        // Mark as active
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => $validated['from'],
            'to' => $validated['to'],
        ], 7200);

        // Dispatch job to emp-refresh queue
        EmpRefreshByDateJob::dispatch(
            $validated['from'],
            $validated['to'],
            $jobId
        );

        return response()->json([
            'message' => 'Refresh job started',
            'job_id' => $jobId,
            'from' => $validated['from'],
            'to' => $validated['to'],
        ], 202);
    }

    /**
     * Get refresh job status.
     *
     * GET /api/admin/emp/refresh/{jobId}
     */
    public function status(string $jobId): JsonResponse
    {
        $status = Cache::get("emp_refresh_{$jobId}");

        if (!$status) {
            return response()->json([
                'error' => 'Job not found',
            ], 404);
        }

        return response()->json($status);
    }

    /**
     * Get current refresh status (is any job running).
     *
     * GET /api/admin/emp/refresh/status
     */
    public function currentStatus(): JsonResponse
    {
        $active = Cache::get('emp_refresh_active');

        if (!$active) {
            return response()->json([
                'is_processing' => false,
            ]);
        }

        // Get detailed status
        $jobStatus = Cache::get("emp_refresh_{$active['job_id']}");

        // Check if completed/failed
        if ($jobStatus && in_array($jobStatus['status'] ?? '', ['completed', 'completed_with_errors', 'failed'])) {
            Cache::forget('emp_refresh_active');
            return response()->json([
                'is_processing' => false,
                'last_job' => array_merge(['job_id' => $active['job_id']], $jobStatus),
            ]);
        }

        return response()->json([
            'is_processing' => true,
            'job_id' => $active['job_id'],
            'started_at' => $active['started_at'],
            'from' => $active['from'] ?? null,
            'to' => $active['to'] ?? null,
            'progress' => $jobStatus['progress'] ?? 0,
            'stats' => $jobStatus['stats'] ?? null,
        ]);
    }
}
