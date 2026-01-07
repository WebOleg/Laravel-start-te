<?php

/**
 * Tests for emerchantpay webhook handling.
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

    public function test_chargeback_webhook_dispatches_job(): void
    {
        Queue::fake();
        
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
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => $originalUniqueId,
            'amount' => 10000,
            'currency' => 'EUR',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'message' => 'Chargeback processing queued']);

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
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => $originalUniqueId,
            'amount' => 10000,
            'currency' => 'EUR',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'message' => 'Chargeback processing queued']);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
    }

    public function test_chargeback_returns_ok_for_unknown_transaction(): void
    {
        Queue::fake();
        
        $uniqueId = 'cb_unknown_789';
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'nonexistent_unique_id',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_chargeback_webhook_prevents_duplicates(): void
    {
        Queue::fake();
        
        $chargebackUniqueId = 'cb_duplicate_789';
        
        $response1 = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'emp_original_123',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $response1->assertOk();
        $this->assertEquals(1, count(Queue::pushed(ProcessEmpWebhookJob::class)));

        $response2 = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'emp_original_123',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $response2->assertOk();
        $response2->assertJson(['message' => 'Webhook already queued']);
        $this->assertEquals(1, count(Queue::pushed(ProcessEmpWebhookJob::class)));
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        Queue::fake();
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => 'test_123',
            'transaction_type' => 'chargeback',
            'signature' => 'invalid_signature',
        ]);

        $response->assertUnauthorized();
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        Queue::fake();
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => 'test_123',
            'transaction_type' => 'chargeback',
        ]);

        $response->assertUnauthorized();
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

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_transaction_status_update_dispatches_job(): void
    {
        Queue::fake();
        
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

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'message' => 'Transaction processing queued']);

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

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $billingAttempt->status);
    }

    public function test_declined_transaction_dispatches_job(): void
    {
        Queue::fake();
        
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

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();

        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_unknown_transaction_type_rejected(): void
    {
        Queue::fake();
        
        $uniqueId = 'unknown_type_123';
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'some_unknown_type',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_rejects_missing_unique_id(): void
    {
        Queue::fake();
        
        $uniqueId = 'test_123';
        $response = $this->postJson('/api/webhooks/emp', [
            'transaction_type' => 'chargeback',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertUnauthorized();
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_rejects_missing_transaction_type(): void
    {
        Queue::fake();
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => 'test_123',
            'signature' => $this->generateSignature('test_123'),
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'error']);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }
}
