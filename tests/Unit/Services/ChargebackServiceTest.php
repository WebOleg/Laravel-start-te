<?php

namespace Tests\Unit\Services;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\ChargebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargebackServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChargebackService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ChargebackService();
    }

    public function test_create_from_webhook_creates_chargeback(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'webhook_test_123',
        ]);

        $webhookData = [
            'reason_code' => 'MD06',
            'reason' => 'Customer requested refund',
            'amount' => 10000,
            'currency' => 'EUR',
            'arn' => '74537604221431003881865',
            'post_date' => '2026-01-22',
        ];

        $chargeback = $this->service->createFromWebhook($billingAttempt, $webhookData);

        $this->assertNotNull($chargeback);
        $this->assertEquals('webhook_test_123', $chargeback->original_transaction_unique_id);
        $this->assertEquals('MD06', $chargeback->reason_code);
        $this->assertEquals(Chargeback::SOURCE_WEBHOOK, $chargeback->source);
        $this->assertEquals(100.00, $chargeback->chargeback_amount);
    }

    public function test_create_from_api_sync_creates_chargeback(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'api_sync_test_456',
        ]);

        $apiResponse = [
            'type' => '1st Chargeback',
            'reason_code' => 'AC04',
            'reason_description' => 'Account closed',
            'chargeback_amount' => 75.50,
            'chargeback_currency' => 'EUR',
            'arn' => '74537604221431003881865',
            'post_date' => '2026-01-20',
            'import_date' => '2026-01-21',
        ];

        $chargeback = $this->service->createFromApiSync($billingAttempt, $apiResponse);

        $this->assertNotNull($chargeback);
        $this->assertEquals('api_sync_test_456', $chargeback->original_transaction_unique_id);
        $this->assertEquals('AC04', $chargeback->reason_code);
        $this->assertEquals('1st Chargeback', $chargeback->type);
        $this->assertEquals(Chargeback::SOURCE_API_SYNC, $chargeback->source);
        $this->assertEquals(75.50, $chargeback->chargeback_amount);
    }

    public function test_create_from_webhook_updates_existing_chargeback(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'update_test_789',
        ]);

        Chargeback::create([
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_id' => $debtor->id,
            'original_transaction_unique_id' => 'update_test_789',
            'reason_code' => 'MD06',
            'source' => Chargeback::SOURCE_WEBHOOK,
        ]);

        $webhookData = [
            'reason_code' => 'MD06',
            'reason' => 'Updated reason',
            'arn' => 'new_arn_123',
        ];

        $chargeback = $this->service->createFromWebhook($billingAttempt, $webhookData);

        $this->assertNotNull($chargeback);
        $this->assertEquals('new_arn_123', $chargeback->arn);
        $this->assertEquals(1, Chargeback::where('original_transaction_unique_id', 'update_test_789')->count());
    }

    public function test_create_returns_null_without_unique_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => null,
        ]);

        $chargeback = $this->service->createFromWebhook($billingAttempt, ['reason_code' => 'MD06']);

        $this->assertNull($chargeback);
    }

    public function test_normalizes_amount_from_minor_units(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'amount_test_123',
        ]);

        $webhookData = [
            'amount' => 15000,
            'currency' => 'EUR',
        ];

        $chargeback = $this->service->createFromWebhook($billingAttempt, $webhookData);

        $this->assertEquals(150.00, $chargeback->chargeback_amount);
    }

    public function test_get_chargebacks_returns_paginated_results(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
        ]);

        $request = new \Illuminate\Http\Request(['per_page' => 10]);
        $result = $this->service->getChargebacks($request);

        $this->assertCount(3, $result);
    }

    public function test_get_chargebacks_filters_by_code(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC04',
        ]);

        $request = new \Illuminate\Http\Request(['code' => 'MD06']);
        $result = $this->service->getChargebacks($request);

        $this->assertCount(1, $result);
        $this->assertEquals('MD06', $result->first()->chargeback_reason_code);
    }

    public function test_get_unique_chargebacks_error_codes(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC04',
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $codes = $this->service->getUniqueChargebacksErrorCodes();

        $this->assertCount(2, $codes);
        $this->assertContains('AC04', $codes);
        $this->assertContains('MD06', $codes);
    }
}
