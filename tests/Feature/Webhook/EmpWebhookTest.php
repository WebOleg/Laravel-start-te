<?php

/**
 * Tests for emerchantpay webhook handling.
 * 
 * EMP requires XML echo response to acknowledge webhook receipt.
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

    public function test_chargeback_webhook_dispatches_job(): void
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

        $chargebackUniqueId = 'cb_unique_456';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => $originalUniqueId,
            'amount' => 10000,
            'currency' => 'EUR',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $chargebackUniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

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

        $chargebackUniqueId = 'cb_unique_456';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => $originalUniqueId,
            'amount' => 10000,
            'currency' => 'EUR',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $chargebackUniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
    }

    public function test_chargeback_returns_ok_for_unknown_transaction(): void
    {
        Queue::fake();
        
        $uniqueId = 'cb_unknown_789';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'nonexistent_unique_id',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_chargeback_webhook_prevents_duplicates(): void
    {
        Queue::fake();
        
        $chargebackUniqueId = 'cb_duplicate_789';
        
        $response1 = $this->post('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'emp_original_123',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $this->assertXmlEchoResponse($response1, $chargebackUniqueId);
        $this->assertCount(1, Queue::pushed(ProcessEmpWebhookJob::class));

        // Second request with same unique_id should be deduplicated
        $response2 = $this->post('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'emp_original_123',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $this->assertXmlEchoResponse($response2, $chargebackUniqueId);
        $this->assertCount(1, Queue::pushed(ProcessEmpWebhookJob::class));
    }

    public function test_webhook_logs_invalid_signature_but_returns_xml(): void
    {
        Queue::fake();
        
        $uniqueId = 'test_123';
        
        // EMP docs: always return XML echo to prevent retries
        // We log the issue but still acknowledge receipt
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'chargeback',
            'signature' => 'invalid_signature',
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_handles_missing_signature(): void
    {
        Queue::fake();
        
        $uniqueId = 'test_123';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'chargeback',
        ]);

        // Returns XML echo to acknowledge receipt, but job not dispatched
        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_transaction_status_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_unique_status_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_status_update_123',
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

    public function test_transaction_status_update_dispatches_job(): void
    {
        Queue::fake();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_unique_status_123';
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_status_update_123',
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
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_declined_transaction_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_unique_declined_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_declined_123',
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

    public function test_declined_transaction_dispatches_job(): void
    {
        Queue::fake();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $uniqueId = 'emp_unique_declined_123';
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_declined_123',
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
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_unknown_transaction_type_acknowledged_but_not_processed(): void
    {
        Queue::fake();
        
        $uniqueId = 'unknown_type_123';
        
        // Unknown types are acknowledged (XML echo) but not processed
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'some_unknown_type',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_handles_missing_unique_id(): void
    {
        Queue::fake();
        
        $response = $this->post('/api/webhooks/emp', [
            'transaction_type' => 'chargeback',
            'signature' => $this->generateSignature('test_123'),
        ]);

        // Missing unique_id - returns XML echo with empty unique_id
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_handles_missing_transaction_type(): void
    {
        Queue::fake();
        
        $uniqueId = 'test_123';
        
        $response = $this->post('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'signature' => $this->generateSignature($uniqueId),
        ]);

        // Missing transaction_type - acknowledged but not processed
        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }
}
