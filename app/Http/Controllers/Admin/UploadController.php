<?php

/**
 * Controller for managing file uploads and debtor validation.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Http\Resources\DebtorResource;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\BillingAttempt;
use App\Services\FileUploadService;
use App\Services\FilePreValidationService;
use App\Services\DebtorValidationService;
use App\Jobs\ProcessValidationJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private FileUploadService $uploadService,
        private FilePreValidationService $preValidationService,
        private DebtorValidationService $validationService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Upload::withCount([
            'debtors',
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_VALID);
            },
            'debtors as invalid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_INVALID);
            },
            'billingAttempts as billed_with_emp_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            },
            'billingAttempts as chargeback_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            },
        ]);

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $uploads = $query->latest()->paginate($request->input('per_page', 20));

        return UploadResource::collection($uploads);
    }

    public function show(Upload $upload): UploadResource
    {
        $upload->load(['uploader']);
        $upload->loadCount([
            'debtors',
            'debtors as valid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_VALID);
            },
            'debtors as invalid_count' => function ($q) {
                $q->where('validation_status', Debtor::VALIDATION_INVALID);
            },
            'debtors as bav_excluded_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->where('name_match', 'no');
                });
            },
            'debtors as bav_passed_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->whereIn('name_match', ['yes', 'partial']);
                });
            },
            'debtors as bav_verified_count' => function ($q) {
                $q->whereHas('vopLogs', function ($vopQuery) {
                    $vopQuery->whereNotNull('name_match');
                });
            },
            'billingAttempts as billed_with_emp_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            },
            'billingAttempts as chargeback_count' => function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            },
        ]);

        return new UploadResource($upload);
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');

            $preValidation = $this->preValidationService->validate($file);
            if (!$preValidation['valid']) {
                return response()->json([
                    'message' => 'File validation failed.',
                    'errors' => $preValidation['errors'],
                ], 422);
            }

            $forceAsync = $request->boolean('async', false);

            if ($forceAsync || $this->shouldProcessAsync($file)) {
                $result = $this->uploadService->processAsync(
                    $file,
                    $request->user()?->id
                );

                return response()->json([
                    'data' => new UploadResource($result['upload']),
                    'meta' => [
                        'queued' => true,
                        'message' => 'File queued for processing. Check status for updates.',
                    ],
                ], 202);
            }

            $result = $this->uploadService->process(
                $file,
                $request->user()?->id
            );

            return response()->json([
                'data' => new UploadResource($result['upload']),
                'meta' => [
                    'queued' => false,
                    'created' => $result['created'],
                    'failed' => $result['failed'],
                    'skipped' => $result['skipped'],
                    'errors' => array_slice($result['errors'], 0, 10),
                ],
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function status(Upload $upload): JsonResponse
    {
        $upload->loadCount('debtors');

        return response()->json([
            'data' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'total_records' => $upload->total_records,
                'processed_records' => $upload->processed_records,
                'failed_records' => $upload->failed_records,
                'debtors_count' => $upload->debtors_count,
                'progress' => $this->calculateProgress($upload),
                'is_complete' => in_array($upload->status, [
                    Upload::STATUS_COMPLETED,
                    Upload::STATUS_FAILED,
                ]),
            ],
        ]);
    }

    public function debtors(Upload $upload, Request $request): AnonymousResourceCollection
    {
        $query = $upload->debtors()->with(['latestBillingAttempt']);

        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->input('validation_status'));
        }

        if ($request->boolean('exclude_chargebacked')) {
            $query->whereDoesntHave('billingAttempts', function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            });
        }

        if ($request->has('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('iban', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        $debtors = $query->latest()->paginate($request->input('per_page', 50));

        return DebtorResource::collection($debtors);
    }

    /**
     * Start async validation for all debtors in upload.
     */
    public function validate(Upload $upload): JsonResponse
    {
        if ($upload->status === Upload::STATUS_PROCESSING) {
            return response()->json([
                'message' => 'Upload is still processing. Please wait.',
            ], 422);
        }

        if ($upload->isValidationProcessing()) {
            return response()->json([
                'message' => 'Validation already in progress.',
                'status' => 'processing',
            ], 200);
        }

        ProcessValidationJob::dispatch($upload);

        return response()->json([
            'message' => 'Validation started',
            'status' => 'processing',
        ], 202);
    }

    public function validationStats(Upload $upload): JsonResponse
    {
        $stats = $upload->debtors()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as valid,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as invalid,
                SUM(CASE WHEN validation_status = ? THEN 1 ELSE 0 END) as pending
            ", [Debtor::VALIDATION_VALID, Debtor::VALIDATION_INVALID, Debtor::VALIDATION_PENDING])
            ->first();

        $driver = DB::connection()->getDriverName();
        $blacklistQuery = $upload->debtors()
            ->where('validation_status', Debtor::VALIDATION_INVALID);
        
        if ($driver === 'pgsql') {
            $blacklisted = $blacklistQuery
                ->whereRaw("validation_errors::text LIKE ?", ['%blacklist%'])
                ->count();
        } else {
            $blacklisted = $blacklistQuery
                ->where('validation_errors', 'like', '%blacklist%')
                ->count();
        }

        $chargebacked = $upload->debtors()
            ->whereHas('billingAttempts', function ($query) {
                $query->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            })
            ->count();

        $meta = $upload->meta ?? [];
        $skipped = $meta['skipped'] ?? null;

        return response()->json([
            'data' => [
                'total' => (int) $stats->total,
                'valid' => (int) $stats->valid,
                'invalid' => (int) $stats->invalid - $blacklisted,
                'pending' => (int) $stats->pending,
                'blacklisted' => $blacklisted,
                'chargebacked' => $chargebacked,
                'ready_for_sync' => $upload->debtors()->readyForSync()->count(),
                'skipped' => $skipped,
                'is_processing' => $upload->isValidationProcessing(),
            ],
        ]);
    }

    public function destroy(Upload $upload): JsonResponse
    {
        if ($upload->canBeHardDeleted()) {
            Storage::disk('s3')->delete($upload->file_path);
            $upload->forceDelete();
            return response()->json([
                'success'   => true,
                'message'   => 'Uploaded File deleted successfully.',
            ], 200);
        }

        if ($upload->canBeSoftDeleted()) {
            $upload->debtors()->delete();
            $upload->delete();
            return response()->json([
                'success' => true,
                'message' => 'Upload and associated debtors deleted successfully.',
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Upload cannot be deleted as it has associated debtors.',
        ], 403);
    }

    public function filterChargebacks(Upload $upload): JsonResponse
    {
        $chargebackedDebtors = $upload->debtors()
            ->whereHas('billingAttempts', function ($query) {
                $query->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            })
            ->get();

        $count = $chargebackedDebtors->count();

        if ($count === 0) {
            return response()->json([
                'message' => 'No chargebacked records found',
                'data' => ['removed' => 0],
            ]);
        }

        foreach ($chargebackedDebtors as $debtor) {
            $debtor->delete();
        }

        return response()->json([
            'message' => "Removed {$count} chargebacked records",
            'data' => ['removed' => $count],
        ]);
    }

    private function shouldProcessAsync($file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['xlsx', 'xls'])) {
            return $file->getSize() > 100 * 1024;
        }

        $lineCount = 0;
        $handle = fopen($file->getPathname(), 'r');
        while (fgets($handle) !== false && $lineCount < self::ASYNC_THRESHOLD + 10) {
            $lineCount++;
        }
        fclose($handle);

        return $lineCount > self::ASYNC_THRESHOLD;
    }

    private function calculateProgress(Upload $upload): float
    {
        if ($upload->total_records === 0) {
            return 0;
        }

        $processed = $upload->processed_records + $upload->failed_records;
        return round(($processed / $upload->total_records) * 100, 2);
    }
}
