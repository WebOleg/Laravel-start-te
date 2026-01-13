<?php

/**
 * Feature tests for BIC Analytics endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\BillingAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BicAnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
    }

    public function test_bic_analytics_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/analytics/bic');

        $response->assertStatus(401);
    }

    public function test_bic_analytics_returns_empty_data_when_no_transactions(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'start_date',
                    'end_date',
                    'threshold',
                    'bics',
                    'totals',
                    'high_risk_count',
                ],
            ])
            ->assertJsonPath('data.bics', [])
            ->assertJsonPath('data.high_risk_count', 0);
    }

    public function test_bic_analytics_returns_aggregated_data(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 50,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.bics')
            ->assertJsonPath('data.bics.0.bic', 'RABONL2UXXX')
            ->assertJsonPath('data.bics.0.bank_country', 'NL')
            ->assertJsonPath('data.bics.0.total_transactions', 7)
            ->assertJsonPath('data.bics.0.approved_count', 5)
            ->assertJsonPath('data.bics.0.declined_count', 2)
            ->assertJsonPath('data.totals.total_transactions', 7);
    }

    public function test_bic_analytics_calculates_chargeback_rate(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'INGBNL2AXXX',
        ]);

        BillingAttempt::factory()->count(9)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonPath('data.bics.0.approved_count', 9)
            ->assertJsonPath('data.bics.0.chargeback_count', 1);

        $data = $response->json('data.bics.0');
        $this->assertEquals(10, $data['cb_rate_count']);
    }

    public function test_bic_analytics_flags_high_risk_bics(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'DEUTESBBXXX',
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonPath('data.bics.0.is_high_risk', true)
            ->assertJsonPath('data.high_risk_count', 1);

        $data = $response->json('data.bics.0');
        $this->assertEquals(50, $data['cb_rate_count']);
    }

    public function test_bic_analytics_supports_period_filter(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(5),
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(15),
        ]);

        $response7d = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?period=7d');

        $response7d->assertStatus(200)
            ->assertJsonPath('data.period', '7d')
            ->assertJsonPath('data.totals.total_transactions', 1);

        $response30d = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?period=30d');

        $response30d->assertStatus(200)
            ->assertJsonPath('data.period', '30d')
            ->assertJsonPath('data.totals.total_transactions', 2);
    }

    public function test_bic_analytics_supports_custom_date_range(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(10),
        ]);

        $startDate = now()->subDays(15)->format('Y-m-d');
        $endDate = now()->subDays(5)->format('Y-m-d');

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/analytics/bic?start_date={$startDate}&end_date={$endDate}");

        $response->assertStatus(200)
            ->assertJsonPath('data.totals.total_transactions', 1);
    }

    public function test_bic_analytics_validates_period(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?period=invalid');

        $response->assertStatus(422);
    }

    public function test_bic_analytics_show_returns_single_bic(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/RABONL2UXXX');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'bic',
                    'bank_country',
                    'total_transactions',
                    'approved_count',
                    'declined_count',
                    'chargeback_count',
                    'total_volume',
                    'cb_rate_count',
                    'is_high_risk',
                ],
            ])
            ->assertJsonPath('data.bic', 'RABONL2UXXX')
            ->assertJsonPath('data.total_transactions', 3);
    }

    public function test_bic_analytics_show_returns_404_for_unknown_bic(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/UNKNOWNBIC');

        $response->assertStatus(404);
    }

    public function test_bic_analytics_export_returns_csv(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get('/api/admin/analytics/bic/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    public function test_bic_analytics_clear_cache(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/analytics/bic/clear-cache');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Cache cleared');
    }

    public function test_bic_analytics_groups_multiple_bics(): void
    {
        $upload = Upload::factory()->create();

        $debtorNL = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        $debtorES = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'DEUTESBBXXX',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorNL->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorES->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.bics')
            ->assertJsonPath('data.totals.total_transactions', 5);
    }

    public function test_bic_analytics_extracts_country_from_bic(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'DEUTESBBXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonPath('data.bics.0.bank_country', 'ES');
    }

    public function test_bic_analytics_calculates_volume_correctly(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.50,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 50.25,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200)
            ->assertJsonPath('data.bics.0.total_volume', 150.75)
            ->assertJsonPath('data.bics.0.approved_volume', 100.50)
            ->assertJsonPath('data.bics.0.chargeback_volume', 50.25);
    }
}
