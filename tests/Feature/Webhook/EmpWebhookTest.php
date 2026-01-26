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
}
