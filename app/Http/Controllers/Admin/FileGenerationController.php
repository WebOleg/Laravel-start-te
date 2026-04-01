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

class FileGenerationController extends Controller
{
    public function __construct(
        private FileClearanceService $clearanceService,
    ) {}

    public function strategies(): JsonResponse
    {
        return response()->json([
            'data' => FileGenerationService::getAvailableStrategies(),
        ]);
    }

    /**
     * Upload source file and start generation.
     *
     * Per-file configs via `files` array:
     *   files[0][amount]=13000&files[0][tolerance]=200&files[1][amount]=5000
     *
     * Or legacy single-file params:
     *   target_amount=13000&tolerance=200&file_count=3
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'              => 'required|file|max:51200|mimes:csv,txt,xlsx,xls',
            'pricing_strategy'  => 'nullable|string',
            'custom_amounts'    => 'nullable|array|min:1',
            'custom_amounts.*'  => 'numeric|min:0.01|max:999.99',

            // Per-file configs (preferred)
            'files'              => 'nullable|array|min:1|max:50',
            'files.*.amount'     => 'required_with:files|numeric|min:1|max:1000000',
            'files.*.tolerance'  => 'nullable|numeric|min:0|max:10000',

            // Legacy single-file params (fallback)
            'target_amount'     => 'required_without:files|numeric|min:1|max:1000000',
            'tolerance'         => 'nullable|numeric|min:0|max:10000',
            'file_count'        => 'nullable|integer|min:1|max:50',
        ]);

        $file          = $request->file('file');
        $strategy      = $request->input('pricing_strategy', FileGenerationService::DEFAULT_STRATEGY);
        $customAmounts = $request->input('custom_amounts');

        $fileConfigs = $this->buildFileConfigs($request);

        try {
            $pricingConfig = FileGenerationService::validateStrategy($strategy, $customAmounts);
            $result = $this->clearanceService->validateAndStore($file);

            $token          = Str::uuid()->toString();
            $primaryAmount  = $fileConfigs[0]['amount'];
            $primaryTol     = $fileConfigs[0]['tolerance'];

            $batch = FileGenerationBatch::create([
                'token'             => $token,
                'admin_id'          => $request->user()?->id,
                'source_file'       => $file->getClientOriginalName(),
                's3_path_source'    => $result['s3_path'],
                'status'            => 'queued',
                'target_amount'     => $primaryAmount,
                'tolerance'         => $primaryTol,
                'pricing_strategy'  => $strategy,
                'total_input_rows'  => $result['total_rows'],
                'file_count'        => count($fileConfigs),
                'file_configs'      => $fileConfigs,
            ]);

            Cache::put("file_generation:{$token}", [
                'status'            => 'queued',
                'total_rows'        => $result['total_rows'],
                'processed'         => 0,
                'phase'             => 'queued',
                'original_file'     => $file->getClientOriginalName(),
                'target_amount'     => $primaryAmount,
                'tolerance'         => $primaryTol,
                'pricing_strategy'  => $strategy,
                'file_count'        => count($fileConfigs),
                'file_configs'      => $fileConfigs,
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
                pricingStrategy: $strategy,
                pricingAmounts: $pricingConfig['amounts'],
                pricingWeights: $pricingConfig['weights'],
                fileConfigs: $fileConfigs,
            );

            Log::info('FileGeneration: job dispatched', [
                'token'        => $token,
                'batch_id'     => $batch->id,
                'total_rows'   => $result['total_rows'],
                'file_configs' => $fileConfigs,
                'file'         => $file->getClientOriginalName(),
            ]);

            return response()->json([
                'data' => [
                    'token'            => $token,
                    'status'           => 'queued',
                    'original_file'    => $file->getClientOriginalName(),
                    'total_rows'       => $result['total_rows'],
                    'target_amount'    => $primaryAmount,
                    'tolerance'        => $primaryTol,
                    'pricing_strategy' => $strategy,
                    'pricing_amounts'  => $pricingConfig['amounts'],
                    'file_count'       => count($fileConfigs),
                    'file_configs'     => $fileConfigs,
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
     * Build per-file configs from request.
     *
     * @return array<int, array{amount: float, tolerance: float}>
     */
    private function buildFileConfigs(Request $request): array
    {
        if ($request->has('files') && is_array($request->input('files'))) {
            $configs = [];
            foreach ($request->input('files') as $fc) {
                $configs[] = [
                    'amount'    => round((float) ($fc['amount'] ?? 0), 2),
                    'tolerance' => round((float) ($fc['tolerance'] ?? 200), 2),
                ];
            }
            return $configs;
        }

        $amount    = (float) $request->input('target_amount');
        $tolerance = (float) $request->input('tolerance', 200);
        $count     = max(1, (int) $request->input('file_count', 1));

        return array_fill(0, $count, [
            'amount'    => round($amount, 2),
            'tolerance' => round($tolerance, 2),
        ]);
    }

    public function status(string $token): JsonResponse
    {
        $data = Cache::get("file_generation:{$token}");

        if (!$data) {
            return response()->json(['message' => 'Token expired or not found.'], 404);
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
                'file_count'                    => $data['file_count'] ?? 1,
                'file_configs'                  => $data['file_configs'] ?? [],
                'excluded_blacklist_rows'       => $data['excluded_blacklist_rows'] ?? 0,
                'excluded_billing_rows'         => $data['excluded_billing_rows'] ?? 0,
                'excluded_previously_used_rows' => $data['excluded_previously_used_rows'] ?? 0,
                'error'                         => $data['error'] ?? null,
                'warning'                       => $data['warning'] ?? null,
                'original_file'                 => $data['original_file'] ?? null,
                'completed_at'                  => $data['completed_at'] ?? null,
                'has_download'                  => !empty($data['s3_path_result']) || !empty($data['s3_result_files']),
                'result_files'                  => $data['s3_result_files'] ?? [],
                'has_leftover'                  => !empty($data['s3_path_leftover']),
                'leftover_rows'                 => $data['leftover_rows'] ?? 0,
            ],
        ]);
    }

    public function download(string $token, Request $request): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $data      = Cache::get("file_generation:{$token}");
        $fileIndex = (int) $request->query('file_index', 0);
        $type      = $request->query('type', 'generated');

        if (!$data || ($data['status'] ?? '') !== 'completed') {
            $batch = FileGenerationBatch::where('token', $token)->where('status', 'completed')->first();
            if (!$batch || !$batch->s3_path_result) {
                return response()->json(['message' => 'File not ready or token expired.'], 404);
            }
            $s3Path   = $batch->s3_path_result;
            $fileName = basename($s3Path);
        } else {
            if ($type === 'leftover') {
                $s3Path   = $data['s3_path_leftover'] ?? null;
                $fileName = $data['leftover_file_name'] ?? 'leftover.csv';
            } else {
                $resultFiles = $data['s3_result_files'] ?? [];
                if (!empty($resultFiles) && isset($resultFiles[$fileIndex])) {
                    $s3Path   = $resultFiles[$fileIndex]['s3_path'];
                    $fileName = $resultFiles[$fileIndex]['file_name'];
                } else {
                    $s3Path   = $data['s3_path_result'] ?? null;
                    $fileName = $data['file_name'] ?? 'generated.csv';
                }
            }
        }

        if (!$s3Path) {
            return response()->json(['message' => 'No output file available.'], 404);
        }

        try {
            return $this->clearanceService->streamDownloadFromS3($s3Path, $fileName);
        } catch (\RuntimeException $e) {
            Log::error('FileGeneration: download failed', [
                'token' => $token, 's3_path' => $s3Path, 'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'File not found. Please run generation again.'], 404);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $batches = FileGenerationBatch::query()
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json($batches);
    }
}
