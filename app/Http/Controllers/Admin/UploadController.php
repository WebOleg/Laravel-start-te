<?php

/**
 * Admin controller for uploads management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Http\Resources\DebtorResource;
use App\Models\Upload;
use App\Models\Debtor;
use App\Services\FileUploadService;
use App\Services\DebtorValidationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UploadController extends Controller
{
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private FileUploadService $uploadService,
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
        ]);

        return new UploadResource($upload);
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
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
        $query = $upload->debtors();

        if ($request->has('validation_status')) {
            $query->where('validation_status', $request->input('validation_status'));
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

    public function validate(Upload $upload): JsonResponse
    {
        if ($upload->status === Upload::STATUS_PROCESSING) {
            return response()->json([
                'message' => 'Upload is still processing. Please wait.',
            ], 422);
        }

        $stats = $this->validationService->validateUpload($upload);

        return response()->json([
            'message' => 'Validation completed',
            'data' => [
                'total' => $stats['total'],
                'valid' => $stats['valid'],
                'invalid' => $stats['invalid'],
            ],
        ]);
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

        $meta = $upload->meta ?? [];
        $skipped = $meta['skipped'] ?? null;

        return response()->json([
            'data' => [
                'total' => (int) $stats->total,
                'valid' => (int) $stats->valid,
                'invalid' => (int) $stats->invalid - $blacklisted,
                'pending' => (int) $stats->pending,
                'blacklisted' => $blacklisted,
                'ready_for_sync' => $upload->debtors()->readyForSync()->count(),
                'skipped' => $skipped,
            ],
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
