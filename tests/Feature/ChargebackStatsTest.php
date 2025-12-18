<?php

/**
 * Tests for chargeback statistics functionality.
 */

namespace Tests\Feature;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Models\VopLog;
use App\Services\ChargebackStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChargebackStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_chargeback_rates_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/admin/stats/chargeback-rates');

        $response->assertUnauthorized();
    }

    public function test_chargeback_rates_returns_empty_stats(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-rates');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'period',
                'start_date',
                'threshold',
                'countries',
                'totals',
            ],
        ]);
    }

    public function test_chargeback_rates_groups_by_country(): void
    {
        $upload = Upload::factory()->create();

        $debtorES = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'ES']);
        $debtorDE = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'DE']);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorES->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorDE->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-rates');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.countries'));
    }

    public function test_chargeback_rates_calculates_cb_rate(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'ES']);

        BillingAttempt::factory()->count(8)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-rates');

        $response->assertOk();
        
        $country = collect($response->json('data.countries'))->firstWhere('country', 'ES');
        $this->assertEquals(10, $country['total']);
        $this->assertEquals(8, $country['approved']);
        $this->assertEquals(2, $country['chargebacks']);
        $this->assertEquals(20, $country['cb_rate_total']);
        $this->assertEquals(25, $country['cb_rate_approved']);
    }

    public function test_chargeback_rates_triggers_alert_above_threshold(): void
    {
        config(['tether.chargeback.alert_threshold' => 10]);

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'NL']);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-rates');

        $country = collect($response->json('data.countries'))->firstWhere('country', 'NL');
        $this->assertTrue($country['alert']);
    }

    public function test_chargeback_rates_accepts_period_param(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-rates?period=30d');

        $response->assertOk();
        $this->assertEquals('30d', $response->json('data.period'));
    }

    public function test_service_calculates_totals(): void
    {
        $upload = Upload::factory()->create();

        $debtorES = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'ES']);
        $debtorDE = Debtor::factory()->create(['upload_id' => $upload->id, 'country' => 'DE']);

        BillingAttempt::factory()->count(10)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorES->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorDE->id,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);

        $service = app(ChargebackStatsService::class);
        $stats = $service->calculateStats('7d');

        $this->assertEquals(15, $stats['totals']['total']);
        $this->assertEquals(10, $stats['totals']['approved']);
        $this->assertEquals(5, $stats['totals']['declined']);
    }

    public function test_chargeback_banks_endpoint_requires_auth(): void
    {
        $response = $this->getJson('/api/admin/stats/chargeback-banks');

        $response->assertUnauthorized();
    }

    public function test_chargeback_banks_returns_empty_stats(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'period',
                'start_date',
                'banks',
                'totals',
            ],
        ]);
    }

    public function test_chargeback_banks_returns_default_period(): void
    {
        // Create test data within the 7d window
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();
        
        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
            'created_at' => now()->subDays(3),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.00,
            'created_at' => now()->subDays(3),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 50.00,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'start_date',
                    'banks' => [
                        '*' => [
                            'bank_name',
                            'total_amount',
                            'chargebacks',
                            'cb_rate',
                        ]
                    ],
                    'totals' => [
                        'total',
                        'total_amount',
                        'chargebacks',
                        'total_cb_rate',
                    ]
                ]
            ]);

        $data = $response->json('data');
        $this->assertEquals('7d', $data['period']);
        $this->assertNotEmpty($data['banks']);
        $this->assertGreaterThan(0, $data['totals']['chargebacks']);
    }

    public function test_chargeback_banks_returns_24h_period_parameter(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        // Create data within 24h
        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Commerzbank',
            'created_at' => now()->subHours(5),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 200.00,
            'created_at' => now()->subHours(5),
        ]);

        // Create data outside 24h window
        $oldDebtor = Debtor::factory()->create();
        VopLog::factory()->create([
            'debtor_id' => $oldDebtor->id,
            'bank_name' => 'Sparkasse',
            'created_at' => now()->subDays(2),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $oldDebtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 500.00,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=24h');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEquals('24h', $data['period']);
        // Should only include recent data
        $bankNames = array_column($data['banks'], 'bank_name');
        $this->assertContains('Commerzbank', $bankNames);
        $this->assertNotContains('Sparkasse', $bankNames);
    }

    public function test_chargeback_banks_returns_30d_period_parameter(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'ING',
            'created_at' => now()->subDays(15),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 150.00,
            'created_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=30d');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEquals('30d', $data['period']);
    }

    public function test_chargeback_banks_returns_90d_period_parameter(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'N26',
            'created_at' => now()->subDays(60),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 300.00,
            'created_at' => now()->subDays(60),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=90d');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEquals('90d', $data['period']);
    }

    public function test_chargeback_banks_percentage_calculation(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
            'created_at' => now()->subDays(3),
        ]);

        // Create 10 approved transactions
        for ($i = 0; $i < 10; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'amount' => 100.00,
                'created_at' => now()->subDays(3),
            ]);
        }

        // Create 2 chargebacks
        for ($i = 0; $i < 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'amount' => 100.00,
                'created_at' => now()->subDays(3),
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEqualsWithDelta(16.67, $data['banks'][0]['cb_rate'], 0.01);
        $this->assertEqualsWithDelta(16.67, $data['totals']['total_cb_rate'], 0.01);
    }

    public function test_chargeback_banks_multiple_aggregated_correctly(): void
    {
        $upload = Upload::factory()->create();

        // Bank 1: Deutsche Bank with 3 approved, 1 chargebacked
        $debtor1 = Debtor::factory()->create();
        VopLog::factory()->create([
            'debtor_id' => $debtor1->id,
            'bank_name' => 'Deutsche Bank',
        ]);

        for ($i = 0; $i < 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor1->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'amount' => 100.00,
            ]);
        }

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor1->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100.00,
        ]);

        // Bank 2: Commerzbank with 2 approved, 2 chargebacked
        $debtor2 = Debtor::factory()->create();
        VopLog::factory()->create([
            'debtor_id' => $debtor2->id,
            'bank_name' => 'Commerzbank',
        ]);

        for ($i = 0; $i < 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor2->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'amount' => 100.00,
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor2->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'amount' => 100.00,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertCount(2, $data['banks']);
        
        // Find banks in result
        $deutscheBank = collect($data['banks'])->firstWhere('bank_name', 'Deutsche Bank');
        $commerzbank = collect($data['banks'])->firstWhere('bank_name', 'Commerzbank');
        
        $this->assertNotNull($deutscheBank);
        $this->assertNotNull($commerzbank);
        
        $this->assertEquals(400.0, $deutscheBank['total_amount']); // 4 * 100
        $this->assertEquals(1, $deutscheBank['chargebacks']);
        $this->assertEqualsWithDelta(25.0, $deutscheBank['cb_rate'], 0.01); // 1/4
        
        $this->assertEquals(400.0, $commerzbank['total_amount']); // 4 * 100
        $this->assertEquals(2, $commerzbank['chargebacks']);
        $this->assertEqualsWithDelta(50.0, $commerzbank['cb_rate'], 0.01); // 2/4
    }

    public function test_chargeback_banks_response_is_cached(): void
    {
        Cache::flush();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        // First request should hit the database and cache
        $response1 = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response1->assertStatus(200);
        $data1 = $response1->json('data');

        // Delete database records
        BillingAttempt::truncate();
        VopLog::truncate();
        Debtor::truncate();

        // Second request should return cached data
        $response2 = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response2->assertStatus(200);
        $data2 = $response2->json('data');

        // Data should be identical (from cache)
        $this->assertEquals($data1, $data2);
    }

    public function test_chargeback_banks_different_periods_have_separate_cache(): void
    {
        Cache::flush();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subHours(12),
        ]);

        // Get 24h period
        $response24h = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=24h');

        $response24h->assertStatus(200);
        $this->assertEquals('24h', $response24h->json('data.period'));

        // Get 7d period
        $response7d = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response7d->assertStatus(200);
        $this->assertEquals('7d', $response7d->json('data.period'));

        // Both should be cached separately
        $this->assertNotEquals($response24h->json('data'), $response7d->json('data'));
    }

    public function test_chargeback_banks_returns_empty_banks_array_when_no_data(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertEmpty($data['banks']);
        $this->assertEquals(0, $data['totals']['total']);
        $this->assertEquals(0, $data['totals']['chargebacks']);
        $this->assertEquals(0.0, $data['totals']['total_cb_rate']);
    }

    public function test_chargeback_banks_total_and_chargebacked_included(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
        ]);

        // Create various status transactions
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'amount' => 100.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 100.00,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Should count all transaction statuses in total, but only chargebacked in chargeback count
        $this->assertEquals(4, $data['totals']['total']);
        $this->assertEquals(1, $data['totals']['chargebacks']);
    }

    public function test_chargeback_banks_multiple_billing_attempts_aggregated_by_bank(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'bank_name' => 'Deutsche Bank',
        ]);

        // Same debtor/bank with multiple attempts
        for ($i = 0; $i < 5; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'amount' => 100.00,
            ]);
        }

        for ($i = 0; $i < 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'amount' => 100.00,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks');

        $response->assertStatus(200);
        $data = $response->json('data');
        
        // All should be aggregated into single bank entry
        $this->assertCount(1, $data['banks']);
        $this->assertEquals(700.0, $data['banks'][0]['total_amount']); // 7 * 100
        $this->assertEquals(2, $data['banks'][0]['chargebacks']);
    }

}
