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
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EmpWebhookController extends Controller
{
    public function __construct(
        private EmpWebhookService $webhookService
    ) {}

    /**
     * Handle incoming EMP webhook.
     *
     * @OA\Post(
     *     path="/api/webhooks/emp/{token}",
     *     summary="Handle EMP webhook notification",
     *     description="Receives webhook notifications from the emerchantpay payment gateway. Validates the signature, deduplicates events, and queues processable events (chargebacks, retrieval requests, SDD status updates) for async processing. Returns an XML echo response with the unique_id as required by EMP to acknowledge receipt and prevent retries. Protected by EmpWebhookSecurity middleware — does not use Bearer auth.",
     *     tags={"Webhooks"},
     *     @OA\Parameter(name="token", in="path", required=true, description="Webhook security token", @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         description="EMP webhook payload (form-encoded or JSON)",
     *         @OA\JsonContent(
     *             @OA\Property(property="unique_id", type="string", description="EMP transaction unique ID", example="abc123def456"),
     *             @OA\Property(property="transaction_type", type="string", description="EMP transaction type", example="sdd_sale"),
     *             @OA\Property(property="status", type="string", description="Transaction status", example="chargebacked"),
     *             @OA\Property(property="event", type="string", nullable=true, description="Event type (chargeback, retrieval_request)", example="chargeback"),
     *             @OA\Property(property="signature", type="string", description="SHA1 signature for verification", example="a1b2c3d4e5f6..."),
     *             @OA\Property(property="amount", type="string", nullable=true, example="4999"),
     *             @OA\Property(property="currency", type="string", nullable=true, example="EUR")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="XML echo response acknowledging receipt",
     *         @OA\MediaType(
     *             mediaType="application/xml",
     *             @OA\Schema(type="string", example="<?xml version='1.0' encoding='UTF-8'?><notification_echo><unique_id>abc123def456</unique_id></notification_echo>")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Missing unique_id or other validation error")
     * )
     */
    public function handle(Request $request): Response
    {
        $uniqueId = $request->input('unique_id');

        try {
            Log::info('EMP webhook received', [
                'unique_id' => $uniqueId,
                'type' => $request->input('transaction_type'),
                'status' => $request->input('status'),
            ]);

            $result = $this->webhookService->process($request);

            // Return XML echo as required by EMP documentation
            return $this->xmlEchoResponse($uniqueId);

        } catch (\InvalidArgumentException $e) {
            Log::warning('EMP webhook validation failed', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage(),
            ]);

            // Still return XML echo for invalid signature to prevent retries
            // But log the issue for investigation
            if ($e->getMessage() === 'Invalid signature') {
                return $this->xmlEchoResponse($uniqueId);
            }

            return response($e->getMessage(), 400);

        } catch (\Exception $e) {
            Log::error('EMP webhook processing error', [
                'unique_id' => $uniqueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return XML echo even on error to acknowledge receipt
            // The webhook data is already logged for manual investigation
            return $this->xmlEchoResponse($uniqueId);
        }
    }

    /**
     * Generate XML echo response as required by EMP.
     */
    private function xmlEchoResponse(?string $uniqueId): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<notification_echo>' . "\n" .
               '<unique_id>' . htmlspecialchars($uniqueId ?? '') . '</unique_id>' . "\n" .
               '</notification_echo>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }
}
