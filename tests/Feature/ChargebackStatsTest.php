<?php

/**
 * Tests for chargeback statistics functionality.
 */

namespace Tests\Feature;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Services\ChargebackStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
