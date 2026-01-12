<?php

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
        // cb_rate_approved = chargebacks / (approved + chargebacks) = 2 / 10 = 20%
        $this->assertEquals(20, $country['cb_rate_approved']);
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
                        'cb_rate',
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

        for ($i = 0; $i < 10; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'amount' => 100.00,
                'created_at' => now()->subDays(3),
            ]);
        }

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

        // CB rate = chargebacks / (approved + chargebacks) = 2 / (10 + 2) = 16.67%
        $this->assertEqualsWithDelta(16.67, $data['banks'][0]['cb_rate'], 0.01);
        $this->assertEqualsWithDelta(16.67, $data['totals']['cb_rate'], 0.01);
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
        
        $deutscheBank = collect($data['banks'])->firstWhere('bank_name', 'Deutsche Bank');
        $commerzbank = collect($data['banks'])->firstWhere('bank_name', 'Commerzbank');
        
        $this->assertNotNull($deutscheBank);
        $this->assertNotNull($commerzbank);
        
        $this->assertEquals(400.0, $deutscheBank['total_amount']);
        $this->assertEquals(1, $deutscheBank['chargebacks']);
        // CB rate = chargebacks / (approved + chargebacks) = 1 / (3 + 1) = 25%
        $this->assertEqualsWithDelta(25.0, $deutscheBank['cb_rate'], 0.01);
        
        $this->assertEquals(400.0, $commerzbank['total_amount']);
        $this->assertEquals(2, $commerzbank['chargebacks']);
        // CB rate = chargebacks / (approved + chargebacks) = 2 / (2 + 2) = 50%
        $this->assertEqualsWithDelta(50.0, $commerzbank['cb_rate'], 0.01);
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

        $response1 = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response1->assertStatus(200);
        $data1 = $response1->json('data');

        BillingAttempt::truncate();
        VopLog::truncate();
        Debtor::truncate();

        $response2 = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response2->assertStatus(200);
        $data2 = $response2->json('data');

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

        $response24h = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=24h');

        $response24h->assertStatus(200);
        $this->assertEquals('24h', $response24h->json('data.period'));

        $response7d = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-banks?period=7d');

        $response7d->assertStatus(200);
        $this->assertEquals('7d', $response7d->json('data.period'));

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
        $this->assertEquals(0.0, $data['totals']['cb_rate']);
    }

    public function test_chargeback_banks_total_and_chargebacked_included(): void
    {
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
        
        $this->assertCount(1, $data['banks']);
        $this->assertEquals(700.0, $data['banks'][0]['total_amount']);
        $this->assertEquals(2, $data['banks'][0]['chargebacks']);
    }

    public function test_chargeback_codes_requires_auth(): void
    {
        $response = $this->getJson('/api/admin/stats/chargeback-codes');

        $response->assertUnauthorized();
    }

    public function test_chargeback_codes_authenticated_user_can_access_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    public function test_chargeback_codes_uses_default_period(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes');

        $response->assertOk();
        $this->assertEquals('7d', $response->json('data.period'));
    }

    public function test_chargeback_codes_with_24h_period(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'created_at' => now()->subHours(2),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB002',
            'created_at' => now()->subDays(5),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=24h');

        $response->assertStatus(200);
        $this->assertEquals('24h', $response->json('data.period'));
        
        $codes = array_column($response->json('data.codes'), 'chargeback_code');
        $this->assertCount(1, $codes);
        $this->assertContains('CB001', $codes);
        $this->assertNotContains('CB002', $codes);
    }

    public function test_chargeback_codes_with_7d_period(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();
        
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'created_at' => now()->subHours(2),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB002',
            'created_at' => now()->subDays(5),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB003',
            'created_at' => now()->subDays(15),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=7d');

        $response->assertStatus(200);
        $this->assertEquals('7d', $response->json('data.period'));
        
        $codes = array_column($response->json('data.codes'), 'chargeback_code');
        $this->assertCount(2, $codes);
        $this->assertContains('CB001', $codes);
        $this->assertContains('CB002', $codes);
        $this->assertNotContains('CB003', $codes);
    }

    public function test_chargeback_codes_with_30d_period(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();
        
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'created_at' => now()->subHours(2),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB002',
            'created_at' => now()->subDays(5),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB003',
            'created_at' => now()->subDays(15),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB004',
            'created_at' => now()->subDays(45),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=30d');

        $response->assertStatus(200);
        $this->assertEquals('30d', $response->json('data.period'));
        
        $codes = array_column($response->json('data.codes'), 'chargeback_code');
        $this->assertCount(3, $codes);
        $this->assertContains('CB001', $codes);
        $this->assertContains('CB002', $codes);
        $this->assertContains('CB003', $codes);
        $this->assertNotContains('CB004', $codes);
    }

    public function test_chargeback_codes_with_90d_period(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();
        
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'created_at' => now()->subHours(2),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB002',
            'created_at' => now()->subDays(5),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB003',
            'created_at' => now()->subDays(15),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB004',
            'created_at' => now()->subDays(45),
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB005',
            'created_at' => now()->subDays(120),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=90d');

        $response->assertStatus(200);
        $this->assertEquals('90d', $response->json('data.period'));
        
        $codes = array_column($response->json('data.codes'), 'chargeback_code');
        $this->assertCount(4, $codes);
        $this->assertContains('CB001', $codes);
        $this->assertContains('CB002', $codes);
        $this->assertContains('CB003', $codes);
        $this->assertContains('CB004', $codes);
        $this->assertNotContains('CB005', $codes);
    }

    public function test_chargeback_codes_requires_get_method(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/stats/chargeback-codes');

        $response->assertMethodNotAllowed();
    }

    public function test_chargeback_codes_different_periods_have_separate_cache(): void
    {
        Cache::flush();
        
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'error_message' => 'Fraud',
            'amount' => 100.00,
            'created_at' => now()->subHours(12),
        ]);

        $response24h = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=24h');

        $response24h->assertStatus(200);
        $this->assertEquals('24h', $response24h->json('data.period'));

        $response7d = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes?period=7d');

        $response7d->assertStatus(200);
        $this->assertEquals('7d', $response7d->json('data.period'));

        $this->assertNotEquals($response24h->json('data'), $response7d->json('data'));
    }

    public function test_chargeback_codes_response_structure(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes');
        
        $response->assertJsonStructure([
            'data' => [
                'period',
                'start_date',
                'codes' => [
                    '*' => [
                        'chargeback_code',
                        'chargeback_reason',
                        'total_amount',
                        'occurrences',
                    ]
                ],
                'totals' => [
                    'total_amount',
                    'occurrences',
                ]
            ]
        ]);
    }

    public function test_chargeback_codes_aggregates_same_code(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'error_code' => 'CB001',
                'error_message' => 'Insufficient Funds',
                'amount' => 100.00,
                'created_at' => now()->subHours($i),
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes');

        $codes = $response->json('data.codes');

        $this->assertCount(1, $codes);
        $this->assertEquals('CB001', $codes[0]['chargeback_code']);
        $this->assertEquals(3, $codes[0]['occurrences']);
        $this->assertEquals(300.00, $codes[0]['total_amount']);
    }

    public function test_chargeback_codes_validates_totals(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create();

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB001',
            'amount' => 150.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'CB002',
            'amount' => 250.00,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/stats/chargeback-codes');

        $data = $response->json('data');
        
        $this->assertEquals(400.00, $data['totals']['total_amount']);
        $this->assertEquals(2, $data['totals']['occurrences']);
    }
}
