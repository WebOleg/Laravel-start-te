<?php

/**
 * Webhook controller for emerchantpay payment gateway notifications.
 * 
 * Handles HTTP concerns only. Business logic delegated to EmpWebhookService.
 */

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Emp\EmpWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EmpWebhookController extends Controller
{
    public function __construct(
        private EmpWebhookService $webhookService
    ) {}

    /**
     * Handle incoming EMP webhook.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            Log::info('EMP webhook received', [
                'unique_id' => $request->input('unique_id'),
                'type' => $request->input('transaction_type'),
            ]);

            $result = $this->webhookService->process($request);

            return response()->json([
                'status' => 'ok',
                'message' => $result['message'] ?? 'Webhook processed',
            ]);

        } catch (\InvalidArgumentException $e) {
            $statusCode = match ($e->getMessage()) {
                'Invalid signature' => 401,
                'Missing unique_id' => 400,
                default => 400,
            };

            return response()->json([
                'error' => $e->getMessage(),
                'status' => 'error',
            ], $statusCode);

        } catch (\Exception $e) {
            Log::error('EMP webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'status' => 'error',
            ], 500);
        }
    }
}
