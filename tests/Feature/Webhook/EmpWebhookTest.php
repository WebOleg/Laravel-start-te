<?php

/**
 * Tests for emerchantpay webhook handling.
 * 
 * EMP Webhook format:
 * - Chargebacks: event=chargeback, unique_id=original_tx, status=chargebacked
 * - Status updates: transaction_type=sdd_sale, status=approved|declined
 * - XML echo required for acknowledgment
 */

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessEmpWebhookJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmpWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $apiPassword = 'test_password';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.emp.password' => $this->apiPassword]);
    }

    private function generateSignature(string $uniqueId): string
    {
        return hash('sha1', $uniqueId . $this->apiPassword);
    }

    /**
     * Assert response is valid XML echo with unique_id.
     */
    private function assertXmlEchoResponse($response, string $uniqueId): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $content = $response->getContent();
        $this->assertStringContainsString('<notification_echo>', $content);
        $this->assertStringContainsString("<unique_id>{$uniqueId}</unique_id>", $content);
    }

    /**
     * Test chargeback webhook with event=chargeback (correct EMP format).
     */
    public function test_chargeback_event_dispatches_job(): void
    {
        Queue::fake();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $originalUniqueId = 'emp_unique_123';
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_original_123',
            'unique_id' => $originalUniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        
        // EMP chargeback format: event=chargeback, unique_id=original transaction
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $originalUniqueId,
            'transaction_type' => 'sdd_sale', // Original transaction type
            'event' => 'chargeback',          // This indicates chargeback!
            'status' => 'chargebacked',
            'amount' => 10000,
            'currency' => 'EUR',
            'reason_code' => 'MD06',
            'arn' => '74537604221431003881865',
            'signature' => $this->generateSignature($originalUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $originalUniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    /**
     * Test chargeback updates billing attempt status.
     */
    public function test_chargeback_updates_billing_attempt_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $originalUniqueId = 'emp_unique_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_original_123',
            'unique_id' => $originalUniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $originalUniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'reason_code' => 'MD06',
            'arn' => '74537604221431003881865',
            'signature' => $this->generateSignature($originalUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $originalUniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
        $this->assertEquals('MD06', $billingAttempt->meta['chargeback']['reason_code']);
    }

    /**
     * Test chargeback for unknown transaction still acknowledged.
     */
    public function test_chargeback_returns_ok_for_unknown_transaction(): void
    {
        Queue::fake();
        
        $uniqueId = 'unknown_tx_789';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    /**
     * Test duplicate chargeback prevention.
     */
    public function test_chargeback_webhook_prevents_duplicates(): void
    {
        Queue::fake();
        
        $uniqueId = 'cb_duplicate_789';
        
        $response1 = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response1, $uniqueId);
        $this->assertCount(1, Queue::pushed(ProcessEmpWebhookJob::class));

        // Second request should be deduplicated
        $response2 = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response2, $uniqueId);
        $this->assertCount(1, Queue::pushed(ProcessEmpWebhookJob::class));
    }

    /**
     * Test SDD status update (approved).
     */
    public function test_sdd_status_update_approved(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_sdd_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_sdd_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    /**
     * Test SDD status update (declined).
     */
    public function test_sdd_status_update_declined(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_sdd_declined_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_sdd_declined_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $billingAttempt->status);
    }

    /**
     * Test retrieval request handling.
     */
    public function test_retrieval_request_logged(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_retrieval_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_retrieval_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'retrieval_request',
            'status' => 'chargebacked',
            'reason_code' => '10',
            'reason_description' => 'Dispute Transaction',
            'arn' => '17b4646c093b025',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        // Status should NOT change for retrieval requests
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
        // But meta should contain retrieval info
        $this->assertArrayHasKey('retrieval_requests', $billingAttempt->meta);
    }

    /**
     * Test invalid signature returns XML but doesn't process.
     */
    public function test_webhook_logs_invalid_signature_but_returns_xml(): void
    {
        Queue::fake();
        
        $uniqueId = 'test_123';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'signature' => 'invalid_signature',
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    /**
     * Test unknown transaction type acknowledged but not processed.
     */
    public function test_unknown_transaction_type_acknowledged(): void
    {
        Queue::fake();
        
        $uniqueId = 'unknown_type_123';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'some_unknown_type',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    /**
     * Test fallback: status=chargebacked without event parameter.
     */
    public function test_chargebacked_status_without_event_processed_as_chargeback(): void
    {
        Queue::fake();
        
        $uniqueId = 'fallback_cb_123';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sale', // Not SDD
            'status' => 'chargebacked',   // But status is chargebacked
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }
}
