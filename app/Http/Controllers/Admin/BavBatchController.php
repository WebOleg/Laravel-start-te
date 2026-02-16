<?php

/**
 * Controller for standalone BAV batch verification.
 * Provides upload, status polling, and result download endpoints.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBavBatchJob;
use App\Models\BavBatch;
use App\Services\BavBatchService;
use App\Services\IbanBavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BavBatchController extends Controller
{
    public function __construct(
        private BavBatchService $bavBatchService,
        private IbanBavService $bavService
    ) {}

    /**
     * List all BAV batches for the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $batches = BavBatch::orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (BavBatch $b) => [
                'id' => $b->id,
                'filename' => $b->original_filename,
                'status' => $b->status,
                'total_records' => $b->total_records,
                'record_limit' => $b->record_limit,
                'processed_records' => $b->processed_records,
                'success_count' => $b->success_count,
                'failed_count' => $b->failed_count,
                'credits_used' => $b->credits_used,
                'progress' => $b->getProgress(),
                'created_at' => $b->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $batches]);
    }

    /**
     * Upload a CSV and create a BAV batch (with preview).
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
     */
    public function balance(): JsonResponse
    {
        $balance = $this->bavService->getBalance();
        return response()->json(['data' => $balance]);
    }
}
