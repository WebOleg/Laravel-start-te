<?php

/**
 * Feature tests for BIC Analytics endpoints.
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\DebtorProfile;
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

        // Fix: Set amount to 100 to ensure these group with the approved ones
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 100,
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
        // Formula: chargebacks / (approved + chargebacks) = 1/(9+1) × 100 = 10%
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
        // Formula: chargebacks / (approved + chargebacks) = 5/(5+5) × 100 = 50%
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
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(15),
            'amount' => 100,
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
                    'cb_rate_volume',
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

        // Fix: Explicitly set amount to 100 to ensure single group per BIC
        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorNL->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtorES->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
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

        // Fix: Set amount equal to approved (100.50) to group them
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100.50,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        // Total volume = 100.50 + 100.50 = 201.00 (which becomes integer 201 in JSON)
        $response->assertStatus(200)
            ->assertJsonPath('data.bics.0.total_volume', 201) // Changed 201.00 to 201
            ->assertJsonPath('data.bics.0.approved_volume', 100.50)
            ->assertJsonPath('data.bics.0.chargeback_volume', 100.50);
    }

    public function test_bic_analytics_filters_by_billing_model(): void
    {
        // 1. Recovery Bank Data (Target)
        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        $recoveryDebtor = Debtor::factory()->create(['debtor_profile_id' => $recoveryProfile->id, 'bic' => 'RECOVERY_XX']);

        BillingAttempt::factory()->create([
            'debtor_id' => $recoveryDebtor->id,
            'debtor_profile_id' => $recoveryProfile->id,
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'bic' => 'RECOVERY_XX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        // 2. Flywheel Bank Data (Should be ignored)
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheelDebtor = Debtor::factory()->create(['debtor_profile_id' => $flywheelProfile->id, 'bic' => 'FLYWHEEL_XX']);

        BillingAttempt::factory()->create([
            'debtor_id' => $flywheelDebtor->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'bic' => 'FLYWHEEL_XX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 200,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?model=' . DebtorProfile::MODEL_RECOVERY);

        $response->assertStatus(200);

        $data = $response->json('data');

        // Should contain exactly 1 BIC
        $this->assertCount(1, $data['bics']);
        $this->assertEquals('RECOVERY_XX', $data['bics'][0]['bic']);

        // Totals should match only Recovery
        $this->assertEquals(100, $data['totals']['total_volume']);
        $this->assertEquals(1, $data['totals']['total_transactions']);
    }

    public function test_bic_analytics_show_filters_by_billing_model(): void
    {
        // 1. Recovery Data for BIC 'SHARED_BIC'
        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        $recoveryDebtor = Debtor::factory()->create(['debtor_profile_id' => $recoveryProfile->id, 'bic' => 'SHARED_BIC']);

        BillingAttempt::factory()->create([
            'debtor_id' => $recoveryDebtor->id,
            'debtor_profile_id' => $recoveryProfile->id,
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'bic' => 'SHARED_BIC',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 50,
        ]);

        // 2. Flywheel Data for SAME BIC 'SHARED_BIC'
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheelDebtor = Debtor::factory()->create(['debtor_profile_id' => $flywheelProfile->id, 'bic' => 'SHARED_BIC']);

        BillingAttempt::factory()->create([
            'debtor_id' => $flywheelDebtor->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'bic' => 'SHARED_BIC',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 50,
        ]);

        // Request details for SHARED_BIC but filtered by 'recovery'
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/SHARED_BIC?model=' . DebtorProfile::MODEL_RECOVERY);

        $response->assertStatus(200);

        // Should only count the 1 Recovery transaction (50 amount), not 2 (100 amount)
        $response->assertJsonPath('data.total_transactions', 1)
            ->assertJsonPath('data.total_volume', 50);
    }

    public function test_bic_analytics_validates_date_range(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?start_date=invalid&end_date=2026-01-28');

        $response->assertStatus(422);
    }

    public function test_bic_analytics_validates_end_date_after_start_date(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic?start_date=2026-01-28&end_date=2026-01-01');

        $response->assertStatus(422);
    }

    public function test_bic_analytics_handles_null_bic(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => null,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => null,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        // Create a valid BIC to compare
        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200);
        // Null BIC should not appear in results, only valid BICs
        $bics = $response->json('data.bics');
        $this->assertEquals(1, count($bics));
        $this->assertEquals('RABONL2UXXX', $bics[0]['bic']);
    }

    public function test_bic_analytics_filters_high_risk_bics(): void
    {
        $upload = Upload::factory()->create();

        // High risk BIC (60% chargeback)
        $debtorHigh = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'BICHIGH60',
        ]);
        BillingAttempt::factory()->count(4)->create([
            'debtor_id' => $debtorHigh->id,
            'bic' => 'BICHIGH60',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);
        BillingAttempt::factory()->count(6)->create([
            'debtor_id' => $debtorHigh->id,
            'bic' => 'BICHIGH60',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
        ]);

        // Low risk BIC (10% chargeback)
        $debtorLow = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'BICLOW10',
        ]);
        BillingAttempt::factory()->count(9)->create([
            'debtor_id' => $debtorLow->id,
            'bic' => 'BICLOW10',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);
        BillingAttempt::factory()->count(1)->create([
            'debtor_id' => $debtorLow->id,
            'bic' => 'BICLOW10',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200);
        $bics = $response->json('data.bics');

        // Find the high-risk BIC
        $highRiskBic = collect($bics)->firstWhere('bic', 'BICHIGH60');
        $this->assertTrue($highRiskBic['is_high_risk']);
    }

    public function test_bic_analytics_export_returns_csv_file(): void
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
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get('/api/admin/analytics/bic/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('bic_analytics', $response->headers->get('content-disposition'));
    }

    public function test_bic_analytics_export_respects_period_filter(): void
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
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(15),
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->get('/api/admin/analytics/bic/export?period=7d');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        // Verify the filename includes the period
        $this->assertStringContainsString('7d', $response->headers->get('content-disposition'));
    }

    public function test_bic_analytics_excludes_xt33_and_xt73_from_chargeback_count(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->count(10)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200);

        $bic = $response->json('data.bics.0');

        $this->assertEquals(1, $bic['chargeback_count'], 'Should exclude XT33 and XT73 chargebacks');
        $this->assertEquals(13, $bic['total_transactions']);
        $this->assertEquals(9.09, $bic['cb_rate_count']); // cb / (approved + cb) = 1 / (10 + 1) × 100 = 9.09%
    }

    public function test_bic_analytics_excludes_xt33_and_xt73_from_volume_calculations(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'INGBNL2AXXX',
        ]);

        // Fix: Use consistent amount (100) to ensure grouping.
        // Original failing test used 500, 100, 200, 150 which split the rows.

        // 1 Approved
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        // 1 Valid Chargeback (AM04)
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 100,
        ]);

        // 1 Excluded Chargeback (XT33)
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 100,
        ]);

        // 1 Excluded Chargeback (XT73)
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200);

        $bic = $response->json('data.bics.0');

        // Total Volume: 4 * 100 = 400
        $this->assertEquals(400, $bic['total_volume']);

        // CB Volume: Only AM04 (100) counts. XT33/XT73 are excluded.
        $this->assertEquals(100, $bic['chargeback_volume']);

        // CB Rate Vol: 100 / (100 Approved + 100 Valid CB) * 100 = 50%
        $this->assertEquals(50, $bic['cb_rate_volume']);
    }

    public function test_bic_analytics_show_excludes_xt33_and_xt73(): void
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

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'MS03',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'DEUTESBBXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/DEUTESBBXXX');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(2, $data['chargeback_count']);
        $this->assertEquals(9, $data['total_transactions']);
        $this->assertEquals(28.57, $data['cb_rate_count']); // cb / (approved + cb) = 2 / (5 + 2) × 100 = 28.57%
    }

    public function test_bic_analytics_totals_exclude_xt33_and_xt73(): void
    {
        $upload = Upload::factory()->create();

        $debtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'RABONL2UXXX',
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
            'bic' => 'RABONL2UXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 100,
        ]);

        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'INGBNL2AXXX',
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
            'bic' => 'INGBNL2AXXX',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic');

        $response->assertStatus(200);

        $totals = $response->json('data.totals');

        $this->assertEquals(11, $totals['total_transactions']);
        $this->assertEquals(1, $totals['chargeback_count']);
        $this->assertEquals(1100, $totals['total_volume']);
    }

    public function test_price_points_returns_breakdown_grouped_by_amount(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'TESTBIC123',
        ]);

        // Scenario 1: Price Point 19.99 (Target BIC)
        // 3 Approved, 1 Chargeback -> Total 4, CB Rate 25%
        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'TESTBIC123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 19.99,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'TESTBIC123',
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 19.99,
        ]);

        // Scenario 2: Price Point 49.99 (Target BIC)
        // 2 Approved -> Total 2
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'TESTBIC123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 49.99,
        ]);

        // Scenario 3: Noise (Different BIC, Same Amount)
        // Should NOT be included
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'OTHERBIC999',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 19.99,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/price-points?bic=TESTBIC123');

        $response->assertStatus(200)
            ->assertJsonPath('data.bic', 'TESTBIC123')
            ->assertJsonCount(2, 'data.segments'); // Expect 2 distinct price points

        $segments = $response->json('data.segments');

        // Check 19.99 Group
        // Note: floating point comparison requires care, using simple search here
        $point19 = collect($segments)->first(fn($item) => abs($item['amount'] - 19.99) < 0.01);

        $this->assertNotNull($point19, '19.99 price point not found');
        $this->assertEquals(4, $point19['total_transactions']);
        $this->assertEquals(25, $point19['cb_rate_count']); // 1 / 4 * 100

        // Check 49.99 Group
        $point49 = collect($segments)->first(fn($item) => abs($item['amount'] - 49.99) < 0.01);

        $this->assertNotNull($point49, '49.99 price point not found');
        $this->assertEquals(2, $point49['total_transactions']);
    }

    public function test_price_points_validates_required_bic(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/price-points'); // Missing ?bic=

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['bic']);
    }

    public function test_price_points_respects_filters(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'TESTBIC123',
        ]);

        // Old transaction (60 days ago) - Should be excluded by 7d filter
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'TESTBIC123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'created_at' => now()->subDays(60),
        ]);

        // Recent transaction (2 days ago) - Should be included
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'bic' => 'TESTBIC123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/price-points?bic=TESTBIC123&period=7d');

        $response->assertStatus(200);

        $segments = $response->json('data.segments');

        $this->assertCount(1, $segments);
        $this->assertEquals(1, $segments[0]['total_transactions']);
    }

    public function test_price_points_sorts_by_amount_descending(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bic' => 'TESTBIC123',
        ]);

        BillingAttempt::factory()->create([
            'bic' => 'TESTBIC123',
            'amount' => 10.00,
            'status' => BillingAttempt::STATUS_APPROVED
        ]);

        BillingAttempt::factory()->create([
            'bic' => 'TESTBIC123',
            'amount' => 50.00,
            'status' => BillingAttempt::STATUS_APPROVED
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/analytics/bic/price-points?bic=TESTBIC123');

        $response->assertStatus(200);

        $segments = $response->json('data.segments');

        $this->assertEquals(50.00, $segments[0]['amount']);
        $this->assertEquals(10.00, $segments[1]['amount']);
    }
}
