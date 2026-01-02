<?php

/**
 * Webhook controller for emerchantpay notifications.
 */

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessEmpWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EmpWebhookController extends Controller
{
    private const VALID_TRANSACTION_TYPES = ['chargeback', 'sdd_sale'];

    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();
        
        Log::info('EMP webhook received', ['unique_id' => $data['unique_id'] ?? null]);

        // Verify signature
        if (!$this->verifySignature($request)) {
            Log::warning('EMP webhook invalid signature', ['unique_id' => $data['unique_id'] ?? null]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $transactionType = $data['transaction_type'] ?? null;

        // Validate transaction type
        if (!isset(array_flip(self::VALID_TRANSACTION_TYPES)[$transactionType])) {
            Log::info('EMP webhook unknown type', ['type' => $transactionType]);
            return response()->json(['status' => 'ok']);
        }

        // Dispatch to queue for processing
        ProcessEmpWebhookJob::dispatch($data, $transactionType, now()->toIso8601String());

        // Return immediately to acknowledge webhook
        return response()->json([
            'status' => 'ok',
            'message' => match ($transactionType) {
                'chargeback' => 'Chargeback processing queued',
                'sdd_sale' => 'Transaction processing queued',
                default => 'Processing queued',
            }
        ]);
    }

    /**
     * Verify EMP webhook signature.
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->input('signature');
        $uniqueId = $request->input('unique_id');
        $apiPassword = config('services.emp.password');

        if (!$signature || !$uniqueId || !$apiPassword) {
            return false;
        }

        $expectedSignature = hash('sha1', $uniqueId . $apiPassword);

        return hash_equals($expectedSignature, $signature);
    }
}
