<?php

/**
 * Controller for standalone BAV batch verification.
 * Provides upload, status polling, and result download endpoints.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BavBatchResource;
use App\Jobs\ProcessBavBatchJob;
use App\Models\BavBatch;
use App\Services\BavBatchService;
use App\Services\IbanBavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Annotations as OA;

class BavBatchController extends Controller
{
    public function __construct(
        private BavBatchService $bavBatchService,
        private IbanBavService $bavService
    ) {}

    /**
     * List all BAV batches for the current user.
     *
     * @OA\Get(
     *     path="/api/admin/bav/batches",
     *     summary="List all BAV batches",
     *     description="Returns the 50 most recent BAV batch records ordered by creation date.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of BAV batches",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="filename", type="string", example="debtors_batch.csv"),
     *                     @OA\Property(property="status", type="string", enum={"pending", "processing", "completed", "failed"}, example="completed"),
     *                     @OA\Property(property="total_records", type="integer", example=500),
     *                     @OA\Property(property="record_limit", type="integer", nullable=true, example=200),
     *                     @OA\Property(property="processed_records", type="integer", example=200),
     *                     @OA\Property(property="success_count", type="integer", example=185),
     *                     @OA\Property(property="failed_count", type="integer", example=15),
     *                     @OA\Property(property="credits_used", type="integer", example=200),
     *                     @OA\Property(property="progress", type="object",
     *                         @OA\Property(property="percentage", type="number", format="float", example=100),
     *                         @OA\Property(property="processed", type="integer", example=200),
     *                         @OA\Property(property="total", type="integer", example=200)
     *                     ),
     *                     @OA\Property(property="created_at", type="string", format="date-time", example="2025-03-15T10:30:00+00:00")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $perPage = min((int) $request->input('per_page', 50), 100);

        $batches = BavBatch::orderByDesc('created_at')
            ->paginate($perPage);

        return BavBatchResource::collection($batches);
    }

    /**
     * Upload a CSV and create a BAV batch (with preview).
     *
     * @OA\Post(
     *     path="/api/admin/bav/batches/upload",
     *     summary="Upload a CSV file to create a BAV batch",
     *     description="Uploads a CSV file, auto-detects IBAN and name columns, validates the structure, and returns a preview of the first rows. The batch is created in 'pending' status and must be started separately.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file"},
     *                 @OA\Property(property="file", type="string", format="binary", description="CSV file (max 5MB, .csv or .txt)")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Batch created successfully with preview",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="batch_id", type="integer", example=1),
     *                 @OA\Property(property="filename", type="string", example="debtors_batch.csv"),
     *                 @OA\Property(property="total_records", type="integer", example=500),
     *                 @OA\Property(property="column_mapping", type="object",
     *                     @OA\Property(property="has_header", type="boolean", example=true),
     *                     @OA\Property(property="delimiter", type="string", example=","),
     *                     @OA\Property(property="iban", type="integer", nullable=true, example=0),
     *                     @OA\Property(property="first_name", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="last_name", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="bic", type="integer", nullable=true, example=3)
     *                 ),
     *                 @OA\Property(property="preview", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="iban", type="string", example="DE89370400440532013000"),
     *                         @OA\Property(property="first_name", type="string", example="Hans"),
     *                         @OA\Property(property="last_name", type="string", example="Mueller"),
     *                         @OA\Property(property="bic", type="string", example="COBADEFFXXX")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error or CSV parsing failure",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Could not detect IBAN column. Ensure CSV contains valid IBANs.")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $result = $this->bavBatchService->uploadAndValidate(
            $request->file('file'),
            $request->user()->id
        );

        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'data' => [
                'batch_id' => $result['batch']->id,
                'filename' => $result['batch']->original_filename,
                'total_records' => $result['batch']->total_records,
                'column_mapping' => $result['batch']->column_mapping,
                'preview' => $result['preview'],
            ]
        ], 201);
    }

    /**
     * Confirm and start processing a BAV batch.
     * Accepts optional record_limit to process only N records.
     *
     * @OA\Post(
     *     path="/api/admin/bav/batches/{id}/start",
     *     summary="Start processing a BAV batch",
     *     description="Confirms and queues a pending BAV batch for processing. Optionally accepts a record_limit to process only a subset of records. Checks BAV credit balance before starting.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="BAV Batch ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="record_limit", type="integer", nullable=true, description="Max number of records to process (defaults to all)", example=100)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Batch queued for processing",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="batch_id", type="integer", example=1),
     *                 @OA\Property(property="status", type="string", example="queued"),
     *                 @OA\Property(property="record_limit", type="integer", example=100),
     *                 @OA\Property(property="message", type="string", example="BAV batch queued for processing (100 of 500 records)")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Batch already started or insufficient credits",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Not enough BAV credits. Need 100, have 50."),
     *             @OA\Property(property="credits_remaining", type="integer", example=50)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Batch not found")
     * )
     */
    public function start(Request $request, int $id): JsonResponse
    {
        $batch = BavBatch::findOrFail($id);

        if ($batch->status !== BavBatch::STATUS_PENDING) {
            return response()->json(['error' => 'Batch is already ' . $batch->status], 422);
        }

        $request->validate([
            'record_limit' => 'nullable|integer|min:1|max:' . $batch->total_records,
        ]);

        $recordLimit = $request->input('record_limit') ? (int) $request->input('record_limit') : $batch->total_records;
        $batch->update(['record_limit' => $recordLimit]);

        $balance = $this->bavService->getBalance();
        if ($balance['success'] && $balance['credits_remaining'] < $recordLimit) {
            return response()->json([
                'error' => "Not enough BAV credits. Need {$recordLimit}, have {$balance['credits_remaining']}.",
                'credits_remaining' => $balance['credits_remaining'],
            ], 422);
        }

        ProcessBavBatchJob::dispatch($batch->id);

        return response()->json([
            'data' => [
                'batch_id' => $batch->id,
                'status' => 'queued',
                'record_limit' => $recordLimit,
                'message' => "BAV batch queued for processing ({$recordLimit} of {$batch->total_records} records)",
            ]
        ]);
    }

    /**
     * Get status/progress of a BAV batch.
     *
     * @OA\Get(
     *     path="/api/admin/bav/batches/{id}/status",
     *     summary="Get BAV batch status and progress",
     *     description="Returns the current processing status and progress of a BAV batch. If the batch is currently processing, it refreshes the progress data before returning.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="BAV Batch ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Batch progress data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="percentage", type="number", format="float", example=65.5),
     *                 @OA\Property(property="processed", type="integer", example=131),
     *                 @OA\Property(property="total", type="integer", example=200),
     *                 @OA\Property(property="status", type="string", enum={"pending", "processing", "completed", "failed"}, example="processing"),
     *                 @OA\Property(property="success_count", type="integer", example=120),
     *                 @OA\Property(property="failed_count", type="integer", example=11)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Batch not found")
     * )
     */
    public function status(int $id): JsonResponse
    {
        $batch = BavBatch::findOrFail($id);

        if ($batch->status === BavBatch::STATUS_PROCESSING) {
            $batch->isProcessing();
            $batch->refresh();
        }

        return response()->json([
            'data' => $batch->getProgress(),
        ]);
    }

    /**
     * Download results CSV of a completed BAV batch.
     *
     * @OA\Get(
     *     path="/api/admin/bav/batches/{id}/download",
     *     summary="Download BAV batch results as CSV",
     *     description="Downloads the results CSV file for a completed BAV batch. The file includes original data plus appended BAV verification columns: bav_valid, bav_name_match, bav_bic, bav_score, bav_result, bav_error.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="BAV Batch ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="CSV file download",
     *         @OA\MediaType(
     *             mediaType="text/csv",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Batch not found or results not available yet",
     *         @OA\JsonContent(
     *             @OA\Property(property="error", type="string", example="Results not available yet")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function download(int $id)
    {
        $batch = BavBatch::findOrFail($id);

        if ($batch->status !== BavBatch::STATUS_COMPLETED || !$batch->results_path) {
            return response()->json(['error' => 'Results not available yet'], 404);
        }

        $content = Storage::disk('s3')->get($batch->results_path);
        $filename = 'bav_results_' . pathinfo($batch->original_filename, PATHINFO_FILENAME) . '_' . $batch->created_at->format('Ymd') . '.csv';

        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Get current BAV credit balance.
     *
     * @OA\Get(
     *     path="/api/admin/bav/batches/balance",
     *     summary="Get current BAV credit balance",
     *     description="Returns the current BAV API credit balance including remaining and total credits.",
     *     tags={"BAV Batches"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Credit balance data",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="success", type="boolean", example=true),
     *                 @OA\Property(property="credits_remaining", type="integer", example=1007),
     *                 @OA\Property(property="credits_total", type="integer", example=2500),
     *                 @OA\Property(property="error", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function balance(): JsonResponse
    {
        $balance = $this->bavService->getBalance();
        return response()->json(['data' => $balance]);
    }
}
