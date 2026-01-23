<?php

namespace App\Services\Emp;

use App\Jobs\ProcessEmpWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EmpWebhookService
{
    private const PROCESSABLE_EVENTS = ['chargeback', 'retrieval_request'];
    private const PROCESSABLE_TRANSACTION_TYPES = ['sdd_sale', 'sdd_init_recurring_sale', 'sdd_recurring_sale'];

    public function process(Request $request): array
    {
        $data = $request->all();
        $uniqueId = $data['unique_id'] ?? null;
        $event = $data['event'] ?? null;
        $transactionType = $data['transaction_type'] ?? 'unknown';
        $status = $data['status'] ?? null;
        $signature = $data['signature'] ?? null;

        Log::info('EMP webhook received', [
            'unique_id' => $uniqueId,
            'type' => $transactionType,
            'status' => $status,
        ]);

        $signatureValid = $this->verifySignature($uniqueId, $signature);
        $processingType = $this->determineProcessingType($event, $transactionType, $status);

        $webhookEvent = WebhookEvent::recordAndCheck([
            'provider' => 'emp',
            'unique_id' => $uniqueId ?? 'missing_' . uniqid(),
            'event_type' => $event,
            'transaction_type' => $transactionType,
            'status' => $status,
            'signature' => $signature,
            'signature_valid' => $signatureValid,
            'processing_type' => $processingType,
            'processing_status' => WebhookEvent::RECEIVED,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload_size' => strlen($request->getContent()),
            'payload' => $data,
            'headers' => $this->extractHeaders($request),
        ]);

        if ($webhookEvent === null) {
            Log::info('EMP webhook duplicate', ['unique_id' => $uniqueId]);
            return [
                'queued' => false,
                'message' => 'Duplicate webhook',
                'unique_id' => $uniqueId,
            ];
        }

        if (!$signatureValid) {
            $webhookEvent->markFailed('Invalid signature');
            Log::warning('EMP webhook invalid signature', [
                'unique_id' => $uniqueId,
                'ip' => $request->ip(),
            ]);
            throw new \InvalidArgumentException('Invalid signature');
        }

        if (!$uniqueId) {
            $webhookEvent->markFailed('Missing unique_id');
            throw new \InvalidArgumentException('Missing unique_id');
        }

        if ($processingType === null) {
            $webhookEvent->markCompleted();
            Log::info('EMP webhook acknowledged (not processed)', [
                'unique_id' => $uniqueId,
                'transaction_type' => $transactionType,
            ]);
            return [
                'queued' => false,
                'message' => 'Webhook acknowledged',
                'unique_id' => $uniqueId,
            ];
        }

        $webhookEvent->markQueued();
        ProcessEmpWebhookJob::dispatch($data, $processingType, now()->toIso8601String(), $webhookEvent->id);

        Log::info('EMP webhook queued', [
            'unique_id' => $uniqueId,
            'processing_type' => $processingType,
            'webhook_event_id' => $webhookEvent->id,
        ]);

        return [
            'queued' => true,
            'message' => 'Processing queued',
            'unique_id' => $uniqueId,
            'type' => $processingType,
        ];
    }

    private function verifySignature(?string $uniqueId, ?string $signature): bool
    {
        if (!$signature || !$uniqueId) {
            return false;
        }

        $apiPassword = config('services.emp.password');
        if (!$apiPassword) {
            return false;
        }

        $expected = hash('sha1', $uniqueId . $apiPassword);
        return hash_equals($expected, $signature);
    }

    private function determineProcessingType(?string $event, string $transactionType, ?string $status): ?string
    {
        if ($event !== null && in_array($event, self::PROCESSABLE_EVENTS, true)) {
            return $event;
        }

        if (in_array($transactionType, self::PROCESSABLE_TRANSACTION_TYPES, true)) {
            if ($status === 'chargebacked') {
                return 'chargeback';
            }
            return 'sdd_status_update';
        }

        if ($status === 'chargebacked') {
            return 'chargeback';
        }

        return null;
    }

    private function extractHeaders(Request $request): array
    {
        $keep = ['content-type', 'content-length', 'user-agent', 'x-forwarded-for', 'x-real-ip'];
        $headers = [];
        foreach ($keep as $name) {
            $value = $request->header($name);
            if ($value) {
                $headers[$name] = $value;
            }
        }
        return $headers;
    }
}
