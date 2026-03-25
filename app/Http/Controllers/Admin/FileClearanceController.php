<?php

/**
 * Controller for the "File Clearance" sidebar feature.
 *
 * Endpoints:
 *   POST  /api/admin/file-clearance                Upload → validate headers → store on S3 → dispatch job
 *   GET   /api/admin/file-clearance/{token}/status  Poll processing progress
 *   GET   /api/admin/file-clearance/{token}/download Download cleaned CSV (streamed from S3)
 *
 * The controller does NO heavy parsing. It reuses FilePreValidationService
 * (same as UploadController::store) to read only headers + a sample,
 * then stores the file on S3 (same as FileUploadService) and dispatches
 * the background job.
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFileClearanceJob;
use App\Services\FileClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

class FileClearanceController extends Controller
{
    public function __construct(
        private FileClearanceService $clearanceService,
    ) {}

    /**
     * Upload a file and dispatch the clearance job.
     *
     * Mirrors the UploadController::store pattern:
     *   1. FilePreValidationService validates headers (lightweight)
     *   2. Raw file stored on S3
     *   3. Job dispatched to queue
     *
     * @OA\Post(
     *     path="/api/admin/file-clearance",
     *     summary="Upload and start file clearance",
     *     description="Validates IBAN column presence (reads header row only), stores file on S3, and dispatches a background job that resolves BICs via VOP and filters blacklisted rows. Returns a token for status polling.",
     *     tags={"File Clearance"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(required={"file"}, @OA\Property(property="file", type="string", format="binary"))
     *         )
     *     ),
     *     @OA\Response(response=202, description="File accepted, processing started"),
     *     @OA\Response(response=422, description="Missing IBAN column or invalid file"),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:51200|mimes:csv,txt,xlsx,xls',
        ]);

        $file = $request->file('file');

        try {
            // 1. Validate headers + store on S3 (lightweight, no full parse)
            $result = $this->clearanceService->validateAndStore($file);

            // 2. Generate token and seed cache
            $token = Str::uuid()->toString();

            Cache::put("file_clearance:{$token}", [
                'status'        => 'queued',
                'total_rows'    => $result['total_rows'],
                'processed'     => 0,
                'original_file' => $file->getClientOriginalName(),
                'admin_id'      => $request->user()?->id,
                'created_at'    => now()->toISOString(),
            ], 7200);

            // 3. Dispatch background job
            ProcessFileClearanceJob::dispatch(
                token: $token,
                s3Path: $result['s3_path'],
                headers: $result['headers'],
                headerMeta: $result['header_meta'],
                originalFileName: $file->getClientOriginalName(),
                totalRows: $result['total_rows'],
            );

            Log::info('FileClearance: job dispatched', [
                'token'      => $token,
                'total_rows' => $result['total_rows'],
                'file'       => $file->getClientOriginalName(),
                's3_path'    => $result['s3_path'],
                'admin_id'   => $request->user()?->id,
            ]);

            return response()->json([
                'data' => [
                    'token'         => $token,
                    'status'        => 'queued',
                    'original_file' => $file->getClientOriginalName(),
                    'total_rows'    => $result['total_rows'],
                    'headers'       => $result['headers'],
                    'message'       => 'File accepted. Processing in background.',
                ],
            ], 202);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('FileClearance: upload failed', [
                'error' => $e->getMessage(),
                'file'  => $file->getClientOriginalName(),
            ]);
            return response()->json([
                'message' => 'File clearance failed. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Poll processing status.
     *
     * @OA\Get(
     *     path="/api/admin/file-clearance/{token}/status",
     *     summary="Get file clearance progress",
     *     tags={"File Clearance"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Processing status"),
     *     @OA\Response(response=404, description="Token not found or expired")
     * )
     */
    public function status(string $token): JsonResponse
    {
        $data = Cache::get("file_clearance:{$token}");

        if (!$data) {
            return response()->json([
                'message' => 'Token expired or not found. Please upload the file again.',
            ], 404);
        }

        $totalRows = $data['total_rows'] ?? 0;
        $processed = $data['processed'] ?? 0;
        $progress  = $totalRows > 0 ? round(($processed / $totalRows) * 100, 1) : 0;

        return response()->json([
            'data' => [
                'status'           => $data['status'] ?? 'queued',
                'total_rows'       => $totalRows,
                'processed'        => $processed,
                'cleared_rows'     => $data['cleared_rows'] ?? 0,
                'excluded_rows'    => $data['excluded_rows'] ?? 0,
                'vop_resolved'     => $data['vop_resolved'] ?? 0,
                'vop_failed'       => $data['vop_failed'] ?? 0,
                'progress'         => $progress,
                'error'            => $data['error'] ?? null,
                'headers'          => $data['headers'] ?? null,
                'excluded_details' => $data['excluded_details'] ?? null,
                'original_file'    => $data['original_file'] ?? null,
                'completed_at'     => $data['completed_at'] ?? null,
            ],
        ]);
    }

    /**
     * Download the cleared CSV (streamed from S3).
     *
     * @OA\Get(
     *     path="/api/admin/file-clearance/{token}/download",
     *     summary="Download cleared file",
     *     tags={"File Clearance"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="CSV file download"),
     *     @OA\Response(response=404, description="Not ready or expired")
     * )
     */
    public function download(string $token): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $data = Cache::get("file_clearance:{$token}");

        if (!$data || ($data['status'] ?? '') !== 'completed') {
            return response()->json([
                'message' => 'File not ready or token expired.',
            ], 404);
        }

        $s3Path = $data['file_path'] ?? null;

        if (!$s3Path) {
            return response()->json([
                'message' => 'Cleaned file not found. Please run clearance again.',
            ], 404);
        }

        try {
            return $this->clearanceService->streamDownloadFromS3(
                $s3Path,
                $data['file_name'] ?? 'cleared.csv',
            );
        } catch (\RuntimeException $e) {
            Log::error('FileClearance: download failed', [
                'token'   => $token,
                's3_path' => $s3Path,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Cleaned file not found. Please run clearance again.',
            ], 404);
        }
    }
}
