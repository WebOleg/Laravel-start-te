<?php

namespace Tests\Unit\Services;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
use App\Models\Debtor;
use App\Models\TetherInstance;
use App\Models\Upload;
use App\Services\ChargebackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    private function createChargebackedAttempt(array $attrs = []): BillingAttempt
    {
        $attempt = BillingAttempt::factory()->create(array_merge([
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ], $attrs));

        DB::table('chargebacks')->insert([
            'billing_attempt_id'             => $attempt->id,
            'debtor_id'                      => $attempt->debtor_id,
            'original_transaction_unique_id' => $attempt->unique_id ?? Str::uuid(),
            'type'                           => '1st chargeback',
            'reason_code'                    => $attempt->chargeback_reason_code,
            'reason_description'             => $attempt->chargeback_reason_description,
            'chargeback_amount'              => $attempt->amount,
            'chargeback_currency'            => $attempt->currency ?? 'EUR',
            'import_date'                    => now()->toDateString(),
            'source'                         => 'api_sync',
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);

        return $attempt;
    }

    public function test_create_from_webhook_creates_chargeback(): void
    {
        $upload         = Upload::factory()->create();
        $debtor         = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'webhook_test_123',
        ]);

        $webhookData = [
            'reason_code' => 'MD06',
            'reason'      => 'Customer requested refund',
            'amount'      => 10000,
            'currency'    => 'EUR',
            'post_date'   => '2026-01-22',
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
        $upload         = Upload::factory()->create();
        $debtor         = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'api_sync_test_456',
        ]);

        $apiResponse = [
            'type'                 => '1st chargeback',
            'reason_code'         => 'AC04',
            'reason_description'  => 'Account closed',
            'chargeback_amount'   => 75.50,
            'chargeback_currency' => 'EUR',
            'post_date'           => '2026-01-20',
            'import_date'         => '2026-01-21',
        ];

        $chargeback = $this->service->createFromApiSync($billingAttempt, $apiResponse);

        $this->assertNotNull($chargeback);
        $this->assertEquals('api_sync_test_456', $chargeback->original_transaction_unique_id);
        $this->assertEquals('AC04', $chargeback->reason_code);
        $this->assertEquals('1st chargeback', $chargeback->type);
        $this->assertEquals(Chargeback::SOURCE_API_SYNC, $chargeback->source);
        $this->assertEquals(75.50, $chargeback->chargeback_amount);
    }

    public function test_create_from_webhook_updates_existing_chargeback(): void
    {
        $upload         = Upload::factory()->create();
        $debtor         = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'update_test_789',
        ]);

        Chargeback::create([
            'billing_attempt_id'             => $billingAttempt->id,
            'debtor_id'                      => $debtor->id,
            'original_transaction_unique_id' => 'update_test_789',
            'reason_code'                    => 'MD06',
            'source'                         => Chargeback::SOURCE_WEBHOOK,
        ]);

        $webhookData = [
            'reason_code' => 'MD06',
            'reason'      => 'Updated reason',
        ];

        $chargeback = $this->service->createFromWebhook($billingAttempt, $webhookData);

        $this->assertNotNull($chargeback);
        $this->assertEquals(1, Chargeback::where('original_transaction_unique_id', 'update_test_789')->count());
    }

    public function test_create_returns_null_without_unique_id(): void
    {
        $upload         = Upload::factory()->create();
        $debtor         = Debtor::factory()->create(['upload_id' => $upload->id]);
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
        $upload         = Upload::factory()->create();
        $debtor         = Debtor::factory()->create(['upload_id' => $upload->id]);
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'amount_test_123',
        ]);

        $webhookData = [
            'amount'   => 15000,
            'currency' => 'EUR',
        ];

        $chargeback = $this->service->createFromWebhook($billingAttempt, $webhookData);

        $this->assertEquals(150.00, $chargeback->chargeback_amount);
    }

    public function test_get_chargebacks_returns_paginated_results(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        for ($i = 0; $i < 3; $i++) {
            $this->createChargebackedAttempt([
                'debtor_id'              => $debtor->id,
                'upload_id'              => $upload->id,
                'chargeback_reason_code' => 'MD06',
            ]);
        }

        $request = new \Illuminate\Http\Request(['per_page' => 10]);
        $result  = $this->service->getChargebacks($request);

        $this->assertCount(3, $result);
    }

    public function test_get_chargebacks_filters_by_code(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $this->createChargebackedAttempt([
            'debtor_id'              => $debtor->id,
            'upload_id'              => $upload->id,
            'chargeback_reason_code' => 'MD06',
        ]);

        $this->createChargebackedAttempt([
            'debtor_id'              => $debtor->id,
            'upload_id'              => $upload->id,
            'chargeback_reason_code' => 'AC04',
        ]);

        $request = new \Illuminate\Http\Request(['code' => 'MD06']);
        $result  = $this->service->getChargebacks($request);

        $this->assertCount(1, $result);
        $this->assertEquals('MD06', $result->first()->reason_code);
    }

    public function test_get_unique_chargebacks_error_codes(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $this->createChargebackedAttempt(['debtor_id' => $debtor->id, 'upload_id' => $upload->id, 'chargeback_reason_code' => 'MD06']);
        $this->createChargebackedAttempt(['debtor_id' => $debtor->id, 'upload_id' => $upload->id, 'chargeback_reason_code' => 'AC04']);
        $this->createChargebackedAttempt(['debtor_id' => $debtor->id, 'upload_id' => $upload->id, 'chargeback_reason_code' => 'MD06']);

        \Illuminate\Support\Facades\Cache::flush();

        $codes = $this->service->getUniqueChargebacksErrorCodes();

        $this->assertCount(2, $codes);
        $this->assertContains('AC04', $codes);
        $this->assertContains('MD06', $codes);
    }

    // ==================== getChargebackStatistics TESTS ====================

    public function test_get_chargeback_statistics_returns_expected_structure(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
            'amount'    => 100,
        ]);

        BillingAttempt::factory()->count(7)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_APPROVED,
            'amount'    => 200,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertArrayHasKey('total_chargebacks_count', $result);
        $this->assertArrayHasKey('total_chargeback_amount', $result);
        $this->assertArrayHasKey('chargeback_rate', $result);
        $this->assertArrayHasKey('average_chargeback_amount', $result);
        $this->assertArrayHasKey('most_common_reason_code', $result);
        $this->assertArrayHasKey('affected_accounts', $result);
        $this->assertArrayHasKey('unique_debtors_count', $result);
        $this->assertArrayHasKey('total_approved_amount', $result);
    }

    public function test_get_chargeback_statistics_correct_counts_and_amounts(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
            'amount'    => 100,
        ]);

        BillingAttempt::factory()->count(7)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_APPROVED,
            'amount'    => 200,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(3, $result['total_chargebacks_count']);
        $this->assertEquals(300.0, $result['total_chargeback_amount']);
        $this->assertEquals(100.0, $result['average_chargeback_amount']);
        $this->assertEquals(1400.0, $result['total_approved_amount']);
        $this->assertEquals(30.0, $result['chargeback_rate']);
    }

    public function test_get_chargeback_statistics_zero_when_no_chargebacks(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(0, $result['total_chargebacks_count']);
        $this->assertEquals(0.0, $result['total_chargeback_amount']);
        $this->assertEquals(0.0, $result['chargeback_rate']);
        $this->assertEquals(0.0, $result['average_chargeback_amount']);
        $this->assertNull($result['most_common_reason_code']);
        $this->assertEquals(0, $result['affected_accounts']);
        $this->assertEquals(0, $result['unique_debtors_count']);
    }

    public function test_get_chargeback_statistics_most_common_reason_code(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
        ]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC04',
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertNotNull($result['most_common_reason_code']);
        $this->assertEquals('MD06', $result['most_common_reason_code']['code']);
        $this->assertEquals(5, $result['most_common_reason_code']['count']);
    }

    public function test_get_chargeback_statistics_filters_by_code(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(4)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AC04',
            'amount'    => 50,
        ]);

        BillingAttempt::factory()->count(6)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MD06',
            'amount'    => 100,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request(['code' => 'AC04']);
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(4, $result['total_chargebacks_count']);
        $this->assertEquals(200.0, $result['total_chargeback_amount']);
    }

    public function test_get_chargeback_statistics_filters_by_emp_account_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $emp1   = \App\Models\EmpAccount::factory()->create();
        $emp2   = \App\Models\EmpAccount::factory()->create();

        BillingAttempt::factory()->count(3)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $emp1->id,
        ]);

        BillingAttempt::factory()->count(7)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $emp2->id,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request(['emp_account_id' => $emp1->id]);
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(3, $result['total_chargebacks_count']);
    }

    public function test_get_chargeback_statistics_filters_by_period_transaction_date_mode(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(4)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_created_at' => now()->subDays(3),
        ]);

        BillingAttempt::factory()->count(6)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_created_at' => now()->subDays(60),
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request(['period' => '7d', 'date_mode' => 'transaction']);
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(4, $result['total_chargebacks_count']);
    }

    public function test_get_chargeback_statistics_filters_by_period_chargeback_date_mode(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id'       => $debtor->id,
            'upload_id'       => $upload->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now()->subDays(3),
        ]);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id'       => $debtor->id,
            'upload_id'       => $upload->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now()->subDays(30),
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request(['period' => '7d', 'date_mode' => 'chargeback']);
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(2, $result['total_chargebacks_count']);
    }

    public function test_get_chargeback_statistics_is_cached(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result1 = $this->service->getChargebackStatistics($request);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $result2 = $this->service->getChargebackStatistics($request);

        $this->assertEquals($result1['total_chargebacks_count'], $result2['total_chargebacks_count']);
    }

    public function test_get_chargeback_statistics_different_filters_use_different_cache_keys(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $emp1   = \App\Models\EmpAccount::factory()->create();

        BillingAttempt::factory()->count(3)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $emp1->id,
        ]);

        BillingAttempt::factory()->count(7)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $allRequest      = new \Illuminate\Http\Request();
        $filteredRequest = new \Illuminate\Http\Request(['emp_account_id' => $emp1->id]);

        $allResult      = $this->service->getChargebackStatistics($allRequest);
        $filteredResult = $this->service->getChargebackStatistics($filteredRequest);

        $this->assertEquals(10, $allResult['total_chargebacks_count']);
        $this->assertEquals(3, $filteredResult['total_chargebacks_count']);
    }

    public function test_get_chargeback_statistics_unique_debtors_count(): void
    {
        $upload  = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor1->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => $debtor2->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(2, $result['unique_debtors_count']);
    }

    // ==================== clearChargebackStatisticsCache TESTS ====================

    public function test_clear_chargeback_statistics_cache_invalidates_cached_stats(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Cache::put('chargeback_stats_version', 1);

        $request = new \Illuminate\Http\Request();
        $result1 = $this->service->getChargebackStatistics($request);
        $this->assertEquals(3, $result1['total_chargebacks_count']);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status'    => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $resultCached = $this->service->getChargebackStatistics($request);
        $this->assertEquals(3, $resultCached['total_chargebacks_count']);

        $this->service->clearChargebackStatisticsCache();

        $result2 = $this->service->getChargebackStatistics($request);
        $this->assertEquals(8, $result2['total_chargebacks_count']);
    }

    public function test_clear_chargeback_statistics_cache_invalidates_all_filter_permutations(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $emp    = \App\Models\EmpAccount::factory()->create();

        BillingAttempt::factory()->count(2)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $emp->id,
        ]);

        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Cache::put('chargeback_stats_version', 1);

        $allRequest      = new \Illuminate\Http\Request();
        $filteredRequest = new \Illuminate\Http\Request(['emp_account_id' => $emp->id]);

        $this->service->getChargebackStatistics($allRequest);
        $this->service->getChargebackStatistics($filteredRequest);

        BillingAttempt::factory()->count(4)->create([
            'debtor_id'      => $debtor->id,
            'upload_id'      => $upload->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'emp_account_id' => $emp->id,
        ]);

        $this->service->clearChargebackStatisticsCache();

        $freshAll      = $this->service->getChargebackStatistics($allRequest);
        $freshFiltered = $this->service->getChargebackStatistics($filteredRequest);

        $this->assertEquals(6, $freshAll['total_chargebacks_count']);
        $this->assertEquals(6, $freshFiltered['total_chargebacks_count']);
    }

    public function test_clear_chargeback_statistics_cache_can_be_called_multiple_times(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        $this->service->clearChargebackStatisticsCache();
        $this->service->clearChargebackStatisticsCache();
        $this->service->clearChargebackStatisticsCache();

        $request = new \Illuminate\Http\Request();
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(0, $result['total_chargebacks_count']);
    }

    public function test_clear_chargeback_statistics_cache_bumps_version(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Cache::put('chargeback_stats_version', 1);

        $versionBefore = \Illuminate\Support\Facades\Cache::get('chargeback_stats_version');

        $this->service->clearChargebackStatisticsCache();

        $versionAfter = \Illuminate\Support\Facades\Cache::get('chargeback_stats_version');

        $this->assertGreaterThan($versionBefore, $versionAfter);
    }

    // ==================== tether_instance_id FILTER TESTS ====================

    public function test_get_chargebacks_filters_by_tether_instance_id(): void
    {
        $instance1 = TetherInstance::factory()->create();
        $instance2 = TetherInstance::factory()->create();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $attempt1 = $this->createChargebackedAttempt([
            'debtor_id'          => $debtor->id,
            'upload_id'          => $upload->id,
            'tether_instance_id' => $instance1->id,
        ]);

        $this->createChargebackedAttempt([
            'debtor_id'          => $debtor->id,
            'upload_id'          => $upload->id,
            'tether_instance_id' => $instance2->id,
        ]);

        $request = new \Illuminate\Http\Request(['tether_instance_id' => $instance1->id]);
        $result  = $this->service->getChargebacks($request);

        $this->assertCount(1, $result);
        $this->assertEquals($attempt1->id, $result->first()->billingAttempt->id);
    }

    public function test_get_chargeback_statistics_filters_by_tether_instance_id(): void
    {
        $instance1 = TetherInstance::factory()->create();
        $instance2 = TetherInstance::factory()->create();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'debtor_id'          => $debtor->id,
            'upload_id'          => $upload->id,
            'status'             => BillingAttempt::STATUS_CHARGEBACKED,
            'amount'             => 100,
            'tether_instance_id' => $instance1->id,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id'          => $debtor->id,
            'upload_id'          => $upload->id,
            'status'             => BillingAttempt::STATUS_CHARGEBACKED,
            'amount'             => 100,
            'tether_instance_id' => $instance2->id,
        ]);

        \Illuminate\Support\Facades\Cache::flush();

        $request = new \Illuminate\Http\Request(['tether_instance_id' => $instance1->id]);
        $result  = $this->service->getChargebackStatistics($request);

        $this->assertEquals(2, $result['total_chargebacks_count']);
    }
}
