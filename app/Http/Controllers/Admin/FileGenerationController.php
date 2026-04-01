<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFileGenerationJob;
use App\Models\FileGenerationBatch;
use App\Services\FileClearanceService;
use App\Services\FileGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class FileGenerationController extends Controller
{
    public function __construct(
        private FileClearanceService $clearanceService,
    ) {}

    /**
     * List available pricing strategies.
     */
    public function strategies(): JsonResponse
    {
        return response()->json([
            'data' => FileGenerationService::getAvailableStrategies(),
        ]);
    }

    /**
     * Upload source file and start generation.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'            => 'required|file|max:51200|mimes:csv,txt,xlsx,xls',
            'target_amount'   => 'required|numeric|min:1|max:1000000',
            'tolerance'       => 'nullable|numeric|min:0|max:10000',
            'pricing_strategy' => 'nullable|string',
            'custom_amounts'  => 'nullable|array|min:1',
            'custom_amounts.*' => 'numeric|min:0.01|max:999.99',
        ]);

        $file          = $request->file('file');
        $targetAmount  = (float) $request->input('target_amount');
        $tolerance     = (float) $request->input('tolerance', 200);
        $strategy      = $request->input('pricing_strategy', FileGenerationService::DEFAULT_STRATEGY);
        $customAmounts = $request->input('custom_amounts');

        try {
            // Validate pricing strategy
            $pricingConfig = FileGenerationService::validateStrategy($strategy, $customAmounts);

            // Validate headers + store on S3
            $result = $this->clearanceService->validateAndStore($file);

            $token = Str::uuid()->toString();

            $batch = FileGenerationBatch::create([
                'token'             => $token,
                'admin_id'          => $request->user()?->id,
                'source_file'       => $file->getClientOriginalName(),
                's3_path_source'    => $result['s3_path'],
                'status'            => 'queued',
                'target_amount'     => $targetAmount,
                'tolerance'         => $tolerance,
                'pricing_strategy'  => $strategy,
                'total_input_rows'  => $result['total_rows'],
            ]);

            Cache::put("file_generation:{$token}", [
                'status'            => 'queued',
                'total_rows'        => $result['total_rows'],
                'processed'         => 0,
                'phase'             => 'queued',
                'original_file'     => $file->getClientOriginalName(),
                'target_amount'     => $targetAmount,
                'tolerance'         => $tolerance,
                'pricing_strategy'  => $strategy,
                'created_at'        => now()->toISOString(),
            ], 7200);

            ProcessFileGenerationJob::dispatch(
                token: $token,
                batchId: $batch->id,
                s3Path: $result['s3_path'],
                headers: $result['headers'],
                headerMeta: $result['header_meta'],
                originalFileName: $file->getClientOriginalName(),
                totalRows: $result['total_rows'],
                targetAmount: $targetAmount,
                tolerance: $tolerance,
                pricingStrategy: $strategy,
                pricingAmounts: $pricingConfig['amounts'],
                pricingWeights: $pricingConfig['weights'],
            );

            Log::info('FileGeneration: job dispatched', [
                'token'            => $token,
                'batch_id'         => $batch->id,
                'total_rows'       => $result['total_rows'],
                'target_amount'    => $targetAmount,
                'tolerance'        => $tolerance,
                'pricing_strategy' => $strategy,
                'file'             => $file->getClientOriginalName(),
            ]);

            return response()->json([
                'data' => [
                    'token'            => $token,
                    'status'           => 'queued',
                    'original_file'    => $file->getClientOriginalName(),
                    'total_rows'       => $result['total_rows'],
                    'target_amount'    => $targetAmount,
                    'tolerance'        => $tolerance,
                    'pricing_strategy' => $strategy,
                    'pricing_amounts'  => $pricingConfig['amounts'],
                    'headers'          => $result['headers'],
                    'message'          => 'File accepted. Generation processing in background.',
                ],
            ], 202);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('FileGeneration: upload failed', [
                'error' => $e->getMessage(),
                'file'  => $file->getClientOriginalName(),
            ]);
            return response()->json([
                'message' => 'File generation failed. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Poll generation progress.
     */
    public function status(string $token): JsonResponse
    {
        $data = Cache::get("file_generation:{$token}");

        if (!$data) {
            return response()->json([
                'message' => 'Token expired or not found.',
            ], 404);
        }

        $totalRows = $data['total_rows'] ?? 0;
        $processed = $data['processed'] ?? 0;
        $progress  = $totalRows > 0 ? round(($processed / $totalRows) * 100, 1) : 0;

        return response()->json([
            'data' => [
                'status'                        => $data['status'] ?? 'queued',
                'phase'                         => $data['phase'] ?? 'queued',
                'total_rows'                    => $totalRows,
                'processed'                     => $processed,
                'progress'                      => $progress,
                'eligible_rows'                 => $data['eligible_rows'] ?? 0,
                'selected_rows'                 => $data['selected_rows'] ?? 0,
                'achieved_amount'               => $data['achieved_amount'] ?? 0,
                'target_amount'                 => $data['target_amount'] ?? 0,
                'tolerance'                     => $data['tolerance'] ?? 0,
                'pricing_strategy'              => $data['pricing_strategy'] ?? null,
                'excluded_blacklist_rows'       => $data['excluded_blacklist_rows'] ?? 0,
                'excluded_billing_rows'         => $data['excluded_billing_rows'] ?? 0,
                'excluded_previously_used_rows' => $data['excluded_previously_used_rows'] ?? 0,
                'error'                         => $data['error'] ?? null,
                'warning'                       => $data['warning'] ?? null,
                'original_file'                 => $data['original_file'] ?? null,
                'completed_at'                  => $data['completed_at'] ?? null,
                'has_download'                  => !empty($data['s3_path_result']),
            ],
        ]);
    }

    /**
     * Download the generated CSV.
     */
    public function download(string $token): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $data = Cache::get("file_generation:{$token}");

        if (!$data || ($data['status'] ?? '') !== 'completed') {
            $batch = FileGenerationBatch::where('token', $token)
                ->where('status', 'completed')
                ->first();

            if (!$batch || !$batch->s3_path_result) {
                return response()->json([
                    'message' => 'File not ready or token expired.',
                ], 404);
            }

            $s3Path   = $batch->s3_path_result;
            $fileName = basename($s3Path);
        } else {
            $s3Path   = $data['s3_path_result'] ?? null;
            $fileName = $data['file_name'] ?? 'generated.csv';
        }

        if (!$s3Path) {
            return response()->json([
                'message' => 'No output file available.',
            ], 404);
        }

        try {
            return $this->clearanceService->streamDownloadFromS3($s3Path, $fileName);
        } catch (\RuntimeException $e) {
            Log::error('FileGeneration: download failed', [
                'token'   => $token,
                's3_path' => $s3Path,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'File not found. Please run generation again.',
            ], 404);
        }
    }

    /**
     * List past generation batches.
     */
    public function history(Request $request): JsonResponse
    {
        $batches = FileGenerationBatch::query()
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($batches);
    }
}
