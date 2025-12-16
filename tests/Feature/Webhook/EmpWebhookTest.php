<?php

/**
 * Tests for emerchantpay webhook handling.
 */

namespace Tests\Feature\Webhook;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_chargeback_updates_billing_attempt_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $originalTxId = 'tx_original_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => $originalTxId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $chargebackUniqueId = 'cb_unique_456';
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $chargebackUniqueId,
            'transaction_type' => 'chargeback',
            'status' => 'approved',
            'original_transaction_unique_id' => $originalTxId,
            'amount' => 10000,
            'currency' => 'EUR',
            'signature' => $this->generateSignature($chargebackUniqueId),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'message' => 'Chargeback processed']);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
    }

    public function test_chargeback_returns_404_for_unknown_transaction(): void
    {
        $uniqueId = 'cb_unknown_789';
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'chargeback',
            'original_transaction_unique_id' => 'nonexistent_tx',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertNotFound();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => 'test_123',
            'transaction_type' => 'chargeback',
            'signature' => 'invalid_signature',
        ]);

        $response->assertUnauthorized();
    }

    public function test_transaction_status_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $txId = 'tx_status_update_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => $txId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $txId,
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($txId),
        ]);

        $response->assertOk();

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_declined_transaction_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $txId = 'tx_declined_123';
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => $txId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $txId,
            'transaction_type' => 'sdd_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($txId),
        ]);

        $response->assertOk();

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $billingAttempt->status);
    }

    public function test_unknown_transaction_type_handled_gracefully(): void
    {
        $uniqueId = 'unknown_type_123';
        
        $response = $this->postJson('/api/webhooks/emp', [
            'unique_id' => $uniqueId,
            'transaction_type' => 'some_unknown_type',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'ok', 'message' => 'Type not handled']);
    }
}
