<?php

/**
 * Admin controller for uploads management.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUploadRequest;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    private const ASYNC_THRESHOLD = 100;

    public function __construct(
        private FileUploadService $uploadService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Upload::withCount('debtors');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $uploads = $query->latest()->paginate($request->input('per_page', 20));

        return UploadResource::collection($uploads);
    }

    public function show(Upload $upload): UploadResource
    {
        $upload->load(['uploader']);
        $upload->loadCount('debtors');

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
