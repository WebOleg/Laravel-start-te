<?php

/**
 * Webhook controller for emerchantpay notifications.
 */

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\BillingAttempt;
use App\Services\IbanValidator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EmpWebhookController extends Controller
{
    public function __construct(
        private IbanValidator $ibanValidator
    ) {}

    /**
     * Handle incoming EMP notification.
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();
        
        Log::info('EMP webhook received', ['data' => $data]);

        // Verify signature
        if (!$this->verifySignature($request)) {
            Log::warning('EMP webhook invalid signature');
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $transactionType = $data['transaction_type'] ?? null;

        return match ($transactionType) {
            'chargeback' => $this->handleChargeback($data),
            'sdd_sale' => $this->handleTransaction($data),
            default => $this->handleUnknown($transactionType),
        };
    }

    /**
     * Handle chargeback notification.
     */
    private function handleChargeback(array $data): JsonResponse
    {
        $originalTxId = $data['original_transaction_unique_id'] ?? null;
        
        if (!$originalTxId) {
            Log::error('Chargeback missing original_transaction_unique_id', $data);
            return response()->json(['error' => 'Missing original transaction'], 400);
        }

        // Find original billing attempt
        $billingAttempt = BillingAttempt::where('transaction_id', $originalTxId)->first();

        if (!$billingAttempt) {
            Log::warning('Chargeback for unknown transaction', ['unique_id' => $originalTxId]);
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Update status to chargebacked
        $billingAttempt->update([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'meta' => array_merge($billingAttempt->meta ?? [], [
                'chargeback' => [
                    'unique_id' => $data['unique_id'] ?? null,
                    'amount' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'received_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        Log::info('Chargeback processed', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $billingAttempt->debtor_id,
            'original_tx' => $originalTxId,
        ]);

        return response()->json(['status' => 'ok', 'message' => 'Chargeback processed']);
    }

    /**
     * Handle regular transaction notification (status update).
     */
    private function handleTransaction(array $data): JsonResponse
    {
        $uniqueId = $data['unique_id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$uniqueId) {
            return response()->json(['error' => 'Missing unique_id'], 400);
        }

        $billingAttempt = BillingAttempt::where('transaction_id', $uniqueId)->first();

        if (!$billingAttempt) {
            Log::info('Transaction notification for unknown tx', ['unique_id' => $uniqueId]);
            return response()->json(['status' => 'ok', 'message' => 'Transaction not tracked']);
        }

        // Map EMP status to our status
        $mappedStatus = $this->mapEmpStatus($status);
        
        if ($mappedStatus && $billingAttempt->status !== $mappedStatus) {
            $billingAttempt->update(['status' => $mappedStatus]);
            Log::info('Transaction status updated', [
                'billing_attempt_id' => $billingAttempt->id,
                'old_status' => $billingAttempt->status,
                'new_status' => $mappedStatus,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle unknown transaction type.
     */
    private function handleUnknown(?string $type): JsonResponse
    {
        Log::info('EMP webhook unknown type', ['type' => $type]);
        return response()->json(['status' => 'ok', 'message' => 'Type not handled']);
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

    /**
     * Map EMP status to BillingAttempt status.
     */
    private function mapEmpStatus(?string $empStatus): ?string
    {
        return match ($empStatus) {
            'approved' => BillingAttempt::STATUS_APPROVED,
            'declined' => BillingAttempt::STATUS_DECLINED,
            'error' => BillingAttempt::STATUS_ERROR,
            'voided' => BillingAttempt::STATUS_VOIDED,
            'pending', 'pending_async' => BillingAttempt::STATUS_PENDING,
            default => null,
        };
    }
}
