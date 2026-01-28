<?php

namespace Tests\Feature\Webhook;

use App\Jobs\ProcessEmpWebhookJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmpWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $apiPassword = 'test_password';
    private string $webhookToken = 'test_webhook_token_123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.emp.password' => $this->apiPassword]);
        config(['services.emp.webhook_token' => $this->webhookToken]);
    }

    private function webhookUrl(): string
    {
        return "/api/webhooks/emp/{$this->webhookToken}";
    }

    private function generateSignature(string $uniqueId): string
    {
        return hash('sha1', $uniqueId . $this->apiPassword);
    }

    private function postWebhook(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            $this->webhookUrl(),
            $data,
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );
    }

    private function assertXmlEchoResponse($response, string $uniqueId): void
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $content = $response->getContent();
        $this->assertStringContainsString('<notification_echo>', $content);
        $this->assertStringContainsString("<unique_id>{$uniqueId}</unique_id>", $content);
    }

    public function test_invalid_token_returns_403(): void
    {
        $response = $this->post(
            '/api/webhooks/emp/wrong-token',
            ['unique_id' => 'test'],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        $response->assertStatus(403);
    }

    public function test_invalid_method_returns_405(): void
    {
        $response = $this->get($this->webhookUrl());
        $response->assertStatus(405);
    }

    public function test_invalid_content_type_returns_415(): void
    {
        $response = $this->postJson($this->webhookUrl(), ['unique_id' => 'test']);
        $response->assertStatus(415);
    }

    public function test_chargeback_event_dispatches_job(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $originalUniqueId = 'emp_unique_' . uniqid();
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_original_123',
            'unique_id' => $originalUniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $originalUniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'amount' => 10000,
            'currency' => 'EUR',
            'reason_code' => 'MD06',
            'signature' => $this->generateSignature($originalUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $originalUniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);

        $this->assertDatabaseHas('webhook_events', [
            'unique_id' => $originalUniqueId,
            'event_type' => 'chargeback',
            'signature_valid' => true,
        ]);
    }

    public function test_chargeback_updates_billing_attempt_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $originalUniqueId = 'emp_unique_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_original_123',
            'unique_id' => $originalUniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $originalUniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'reason_code' => 'MD06',
            'signature' => $this->generateSignature($originalUniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $originalUniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
        $this->assertEquals('MD06', $billingAttempt->meta['chargeback']['reason_code']);
    }

    public function test_chargeback_returns_ok_for_unknown_transaction(): void
    {
        Queue::fake();

        $uniqueId = 'unknown_tx_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_chargeback_webhook_prevents_duplicates(): void
    {
        Queue::fake();

        $uniqueId = 'cb_duplicate_' . uniqid();

        $response1 = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response1, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class, 1);

        $response2 = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response2, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class, 1);

        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_sdd_status_update_approved(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'emp_sdd_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_sdd_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_sdd_status_update_declined(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'emp_sdd_declined_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_sdd_declined_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $billingAttempt->status);
    }

    public function test_webhook_logs_invalid_signature_but_returns_xml(): void
    {
        Queue::fake();

        $uniqueId = 'test_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'signature' => 'invalid_signature',
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);

        $this->assertDatabaseHas('webhook_events', [
            'unique_id' => $uniqueId,
            'signature_valid' => false,
            'processing_status' => WebhookEvent::FAILED,
        ]);
    }

    public function test_unknown_transaction_type_acknowledged(): void
    {
        Queue::fake();

        $uniqueId = 'unknown_type_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'some_unknown_type',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertNotPushed(ProcessEmpWebhookJob::class);
    }

    public function test_chargebacked_status_without_event_processed_as_chargeback(): void
    {
        Queue::fake();

        $uniqueId = 'fallback_cb_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sale',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_event_audit_trail(): void
    {
        Queue::fake();

        $uniqueId = 'audit_' . uniqid();

        $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $event = WebhookEvent::where('unique_id', $uniqueId)->first();

        $this->assertNotNull($event);
        $this->assertEquals('emp', $event->provider);
        $this->assertEquals('chargeback', $event->event_type);
        $this->assertEquals('sdd_sale', $event->transaction_type);
        $this->assertTrue($event->signature_valid);
        $this->assertEquals(WebhookEvent::QUEUED, $event->processing_status);
        $this->assertNotNull($event->ip_address);
        $this->assertIsArray($event->payload);
    }

    // Edge Case Tests
    
    public function test_missing_unique_id_still_returns_xml(): void
    {
        $response = $this->post(
            $this->webhookUrl(),
            ['event' => 'chargeback', 'status' => 'chargebacked'],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $content = $response->getContent();
        $this->assertStringContainsString('<notification_echo>', $content);
    }

    public function test_empty_unique_id_still_returns_xml(): void
    {
        $response = $this->postWebhook(['unique_id' => '']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
    }

    public function test_null_signature_rejected(): void
    {
        $uniqueId = 'no_sig_' . uniqid();

        $response = $this->post(
            $this->webhookUrl(),
            [
                'unique_id' => $uniqueId,
                'event' => 'chargeback',
                'status' => 'chargebacked',
            ],
            ['Content-Type' => 'application/x-www-form-urlencoded']
        );

        $response->assertOk();
        $this->assertDatabaseHas('webhook_events', [
            'unique_id' => $uniqueId,
            'signature_valid' => false,
        ]);
    }

    public function test_retrieval_request_event_queued(): void
    {
        Queue::fake();

        $uniqueId = 'retrieval_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'retrieval_request',
            'status' => 'retrieval_requested',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);

        $this->assertDatabaseHas('webhook_events', [
            'unique_id' => $uniqueId,
            'event_type' => 'retrieval_request',
            'processing_status' => WebhookEvent::QUEUED,
        ]);
    }

    public function test_sdd_init_recurring_sale_status_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'sdd_init_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_init_recurring_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_init_recurring_sale',
            'status' => 'approved',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_sdd_recurring_sale_status_update(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'sdd_recurring_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_recurring_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_recurring_sale',
            'status' => 'declined',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $billingAttempt->status);
    }

    public function test_webhook_with_extra_fields_handled_gracefully(): void
    {
        Queue::fake();

        $uniqueId = 'extra_fields_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
            'extra_field_1' => 'should be ignored',
            'extra_field_2' => 'also ignored',
            'custom_data' => json_encode(['nested' => 'data']),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);

        $event = WebhookEvent::where('unique_id', $uniqueId)->first();
        $this->assertIsArray($event->payload);
        $this->assertArrayHasKey('extra_field_1', $event->payload);
    }

    public function test_chargeback_with_multiple_reason_codes(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $reasonCodes = ['MD06', 'C05', 'R02', 'PIND'];
        
        foreach ($reasonCodes as $reasonCode) {
            $uniqueId = 'chargeback_' . $reasonCode . '_' . uniqid();
            $billingAttempt = BillingAttempt::create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'transaction_id' => 'tx_' . $reasonCode,
                'unique_id' => $uniqueId,
                'amount' => 100,
                'status' => BillingAttempt::STATUS_APPROVED,
            ]);

            $response = $this->postWebhook([
                'unique_id' => $uniqueId,
                'transaction_type' => 'sdd_sale',
                'event' => 'chargeback',
                'status' => 'chargebacked',
                'reason_code' => $reasonCode,
                'signature' => $this->generateSignature($uniqueId),
            ]);

            $this->assertXmlEchoResponse($response, $uniqueId);
            
            $billingAttempt->refresh();
            $this->assertEquals($reasonCode, $billingAttempt->meta['chargeback']['reason_code']);
        }
    }

    public function test_webhook_with_special_characters_in_unique_id(): void
    {
        Queue::fake();

        $uniqueId = 'special_chars_' . uniqid() . '_!@#$%';

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_stores_ip_address(): void
    {
        Queue::fake();

        $uniqueId = 'ip_test_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $event = WebhookEvent::where('unique_id', $uniqueId)->first();
        $this->assertNotNull($event->ip_address);
        $this->assertIsString($event->ip_address);
    }

    public function test_webhook_stores_user_agent(): void
    {
        Queue::fake();

        $uniqueId = 'ua_test_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $event = WebhookEvent::where('unique_id', $uniqueId)->first();
        $this->assertNotNull($event->user_agent);
    }

    public function test_chargebacked_status_with_declined_transaction_type(): void
    {
        Queue::fake();

        $uniqueId = 'decline_chargeback_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'card_sale', // Non-SDD type
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        // Chargebacked status should still queue due to fallback logic
        Queue::assertPushed(ProcessEmpWebhookJob::class);
    }

    public function test_webhook_with_very_long_unique_id(): void
    {
        Queue::fake();

        $uniqueId = 'long_id_' . str_repeat('x', 500) . '_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        Queue::assertPushed(ProcessEmpWebhookJob::class);

        $event = WebhookEvent::where('unique_id', $uniqueId)->first();
        $this->assertNotNull($event);
    }

    public function test_missing_status_field_handled(): void
    {
        Queue::fake();

        $uniqueId = 'no_status_' . uniqid();

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'signature' => $this->generateSignature($uniqueId),
            // status field is missing
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);
        
        $event = WebhookEvent::where('unique_id', $uniqueId)->first();
        $this->assertNull($event->status);
    }

    public function test_webhook_event_creation_idempotent(): void
    {
        Queue::fake();

        $uniqueId = 'idempotent_' . uniqid();

        // Send same webhook 3 times
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postWebhook([
                'unique_id' => $uniqueId,
                'transaction_type' => 'sdd_sale',
                'event' => 'chargeback',
                'status' => 'chargebacked',
                'signature' => $this->generateSignature($uniqueId),
            ]);

            $this->assertXmlEchoResponse($response, $uniqueId);
        }

        // Only 1 job should be queued (from first webhook)
        Queue::assertPushed(ProcessEmpWebhookJob::class, 1);
        
        // Only 1 event should exist in database
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_chargeback_with_currency_field(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'currency_test_' . uniqid();
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_currency_123',
            'unique_id' => $uniqueId,
            'amount' => 100,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->postWebhook([
            'unique_id' => $uniqueId,
            'transaction_type' => 'sdd_sale',
            'event' => 'chargeback',
            'status' => 'chargebacked',
            'reason_code' => 'MD06',
            'currency' => 'EUR',
            'amount' => 15000,
            'signature' => $this->generateSignature($uniqueId),
        ]);

        $this->assertXmlEchoResponse($response, $uniqueId);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertArrayHasKey('chargeback', $billingAttempt->meta);
    }
}
