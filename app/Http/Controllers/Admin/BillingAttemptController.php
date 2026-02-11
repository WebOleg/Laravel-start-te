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
use App\Services\Emp\EmpBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillingAttemptController extends Controller
{
    private const STREAMING_THRESHOLD = 10000;

    /**
     * List billing attempts with filters.
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
     */
    public function show(BillingAttempt $billingAttempt): BillingAttemptResource
    {
        $billingAttempt->load(['debtor', 'upload']);

        return new BillingAttemptResource($billingAttempt);
    }

    /**
     * Retry failed billing attempt.
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
     * Mode: broad (>=1 approved charge) or strict (>=2 approved charges).
     * Lifetime CB exclusion: anyone who EVER had a chargeback is excluded.
     */
    public function cleanUsersStats(Request $request): JsonResponse
    {
        $request->validate([
            'min_days' => 'nullable|integer|min:1|max:365',
            'mode' => 'nullable|in:broad,strict',
        ]);

        $minDays = (int) $request->input('min_days', 30);
        $mode = $request->input('mode', 'broad');
        $cutoffDate = now()->subDays($minDays);

        $count = $this->buildCleanUsersQuery($cutoffDate, $mode)->count();

        return response()->json([
            'data' => [
                'count' => $count,
                'min_days' => $minDays,
                'mode' => $mode,
                'cutoff_date' => $cutoffDate->toDateString(),
                'streaming_threshold' => self::STREAMING_THRESHOLD,
            ],
        ]);
    }

    /**
     * Export clean users - streaming for small, job for large.
     */
    public function exportCleanUsers(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'limit' => 'required|integer|min:1|max:100000',
            'min_days' => 'nullable|integer|min:1|max:365',
            'mode' => 'nullable|in:broad,strict',
        ]);

        $limit = (int) $request->input('limit');
        $minDays = (int) $request->input('min_days', 30);
        $mode = $request->input('mode', 'broad');

        // For small exports - use streaming
        if ($limit <= self::STREAMING_THRESHOLD) {
            return $this->streamCleanUsers($limit, $minDays, $mode);
        }

        // For large exports - use background job
        return $this->queueCleanUsersExport($limit, $minDays, $mode);
    }

    /**
     * Get export job status.
     */
    public function exportStatus(string $jobId): JsonResponse
    {
        $status = Cache::get("clean_users_export:{$jobId}");

        if (!$status) {
            return response()->json([
                'message' => 'Export job not found',
            ], 404);
        }

        // Add download URL if completed
        if ($status['status'] === 'completed' && isset($status['path'])) {
            $status['download_url'] = route('admin.clean-users.download', ['jobId' => $jobId]);
        }

        return response()->json([
            'data' => $status,
        ]);
    }

    /**
     * Download completed export file - stream from S3 through Laravel.
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
    private function streamCleanUsers(int $limit, int $minDays, string $mode = 'broad'): StreamedResponse
    {
        $cutoffDate = now()->subDays($minDays);
        $filename = 'clean_users_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($cutoffDate, $limit, $mode) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['first_name', 'last_name', 'iban', 'bic', 'amount', 'currency']);

            $query = $this->buildCleanUsersQuery($cutoffDate, $mode)
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
    private function queueCleanUsersExport(int $limit, int $minDays, string $mode = 'broad'): JsonResponse
    {
        $jobId = Str::uuid()->toString();

        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'processed' => 0,
            'limit' => $limit,
            'min_days' => $minDays,
            'mode' => $mode,
            'created_at' => now()->toISOString(),
        ], now()->addHours(24));

        ExportCleanUsersJob::dispatch($jobId, $limit, $minDays, $mode);

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
     * Broad: >=1 approved charge, no lifetime CB.
     * Strict: >=2 approved charges, no lifetime CB.
     */
    private function buildCleanUsersQuery(\Carbon\Carbon $cutoffDate, string $mode = 'broad')
    {
        $chargebackedSubquery = BillingAttempt::select('debtor_id')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereNotNull('debtor_id')
            ->distinct();

        $query = BillingAttempt::query()
            ->where('status', BillingAttempt::STATUS_APPROVED)
            ->where('attempt_number', 1)
            ->where('emp_created_at', '<=', $cutoffDate)
            ->whereNotNull('debtor_id')
            ->whereNotIn('debtor_id', $chargebackedSubquery);

        if ($mode === 'strict') {
            $debtorsWithMultiple = BillingAttempt::select('debtor_id')
                ->where('status', BillingAttempt::STATUS_APPROVED)
                ->whereNotNull('debtor_id')
                ->groupBy('debtor_id')
                ->havingRaw('COUNT(*) >= 2');

            $query->whereIn('debtor_id', $debtorsWithMultiple);
        }

        return $query->oldest('emp_created_at');
    }
}
