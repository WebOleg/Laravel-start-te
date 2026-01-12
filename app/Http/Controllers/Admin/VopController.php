<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVopJob;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VopController extends Controller
{
    public function __construct(
        private VopVerificationService $vopService
    ) {}

    public function stats(Upload $upload): JsonResponse
    {
        $stats = $this->vopService->getUploadStats($upload->id);
        return response()->json(['data' => $stats]);
    }

    public function verify(Upload $upload, Request $request): JsonResponse
    {
        $forceRefresh = $request->boolean('force', false);

        if ($upload->isVopProcessing() && !$forceRefresh) {
            return response()->json([
                'message' => 'VOP verification already in progress',
                'data' => [
                    'upload_id' => $upload->id,
                    'queued' => true,
                    'duplicate' => true,
                ],
            ], 409);
        }

        ProcessVopJob::dispatch($upload, $forceRefresh);

        return response()->json([
            'message' => 'VOP verification started',
            'data' => [
                'upload_id' => $upload->id,
                'force_refresh' => $forceRefresh,
            ],
        ], 202);
    }

    public function verifySingle(Request $request): JsonResponse
    {
        $request->validate([
            'iban' => 'required|string',
            'name' => 'required|string',
            'use_mock' => 'boolean',
        ]);

        $useMock = $request->boolean('use_mock', true);
        
        config(['services.iban.mock' => $useMock]);
        
        $bavService = app(\App\Services\IbanBavService::class);
        $result = $bavService->verify($request->iban, $request->name);

        return response()->json([
            'data' => $result,
            'meta' => [
                'mock_mode' => $useMock,
                'credits_used' => $useMock ? 0 : 1,
            ],
        ]);
    }

    public function logs(Upload $upload): JsonResponse
    {
        $logs = VopLog::where('upload_id', $upload->id)
            ->with('debtor:id,first_name,last_name,iban')
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($logs);
    }
}
