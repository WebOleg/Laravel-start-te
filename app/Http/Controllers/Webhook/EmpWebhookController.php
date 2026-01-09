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
     * EMP requires XML echo response with unique_id to acknowledge receipt.
     * If not received, EMP will retry the notification.
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
