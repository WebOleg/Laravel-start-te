<?php

/**
 * Feature tests for Admin Dashboard endpoint.
 *
 * CB Rate formula: chargebacks / approved (EMP-aligned).
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Models\BillingAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class DashboardTest extends TestCase
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

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/dashboard');

        $response->assertStatus(401);
    }

    public function test_dashboard_returns_all_sections(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'uploads',
                    'debtors',
                    'vop',
                    'billing',
                    'recent_activity',
                    'trends',
                    'filters',
                ],
            ]);
    }

    public function test_dashboard_returns_filters(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'filters' => [
                        'month',
                        'year',
                    ],
                ],
            ]);
    }

    public function test_dashboard_returns_upload_stats(): void
    {
        Upload::factory()->count(3)->create(['status' => Upload::STATUS_COMPLETED]);
        Upload::factory()->count(2)->create(['status' => Upload::STATUS_PENDING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.total', 5)
            ->assertJsonPath('data.uploads.completed', 3)
            ->assertJsonPath('data.uploads.pending', 2);
    }

    public function test_dashboard_upload_stats_includes_all_statuses(): void
    {
        Upload::factory()->create(['status' => Upload::STATUS_PENDING]);
        Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED]);
        Upload::factory()->create(['status' => Upload::STATUS_FAILED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.total', 4)
            ->assertJsonPath('data.uploads.pending', 1)
            ->assertJsonPath('data.uploads.processing', 1)
            ->assertJsonPath('data.uploads.completed', 1)
            ->assertJsonPath('data.uploads.failed', 1);
    }

    public function test_dashboard_upload_stats_today_count(): void
    {
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()]);
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()]);
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()->subDays(2)]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.today', 2);
    }

    public function test_dashboard_upload_stats_this_week_count(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 2, 5, 12, 0, 0));

        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()]);
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()->subDay()]);
        Upload::factory()->create(['status' => Upload::STATUS_COMPLETED, 'created_at' => now()->subDays(8)]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.this_week', 2);

        Carbon::setTestNow();
    }

    public function test_dashboard_returns_debtor_stats(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PENDING,
        ]);
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_RECOVERED,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'debtor_id' => null,
            'upload_id' => null,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);
        BillingAttempt::factory()->count(2)->create([
            'debtor_id' => null,
            'upload_id' => null,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 50,
        ]);
        BillingAttempt::factory()->count(3)->create([
            'debtor_id' => null,
            'upload_id' => null,
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 75,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.total', 8)
            ->assertJsonPath('data.debtors.by_status.pending', 5)
            ->assertJsonPath('data.debtors.by_status.recovered', 3)
            ->assertJsonPath('data.debtors.total_amount', 825)
            ->assertJsonPath('data.debtors.recovered_amount', 400)
            ->assertJsonPath('data.debtors.recovery_rate', 48.48);
    }

    public function test_dashboard_debtor_stats_all_statuses(): void
    {
        Debtor::factory()->create(['status' => Debtor::STATUS_PENDING]);
        Debtor::factory()->create(['status' => Debtor::STATUS_PROCESSING]);
        Debtor::factory()->create(['status' => Debtor::STATUS_APPROVED]);
        Debtor::factory()->create(['status' => Debtor::STATUS_CHARGEBACKED]);
        Debtor::factory()->create(['status' => Debtor::STATUS_RECOVERED]);
        Debtor::factory()->create(['status' => Debtor::STATUS_FAILED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.total', 6)
            ->assertJsonPath('data.debtors.by_status.pending', 1)
            ->assertJsonPath('data.debtors.by_status.processing', 1)
            ->assertJsonPath('data.debtors.by_status.approved', 1)
            ->assertJsonPath('data.debtors.by_status.chargebacked', 1)
            ->assertJsonPath('data.debtors.by_status.recovered', 1)
            ->assertJsonPath('data.debtors.by_status.failed', 1);
    }

    public function test_dashboard_debtor_recovery_rate_zero_when_no_billing(): void
    {
        Debtor::factory()->count(5)->create(['status' => Debtor::STATUS_PENDING]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.total_amount', 0)
            ->assertJsonPath('data.debtors.recovered_amount', 0)
            ->assertJsonPath('data.debtors.recovery_rate', 0);
    }

    public function test_dashboard_debtor_by_country(): void
    {
        Debtor::factory()->count(3)->create(['country' => 'NL']);
        Debtor::factory()->count(2)->create(['country' => 'DE']);
        Debtor::factory()->count(1)->create(['country' => 'BE']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.by_country.NL', 3)
            ->assertJsonPath('data.debtors.by_country.DE', 2)
            ->assertJsonPath('data.debtors.by_country.BE', 1);
    }

    public function test_dashboard_debtor_valid_iban_rate(): void
    {
        Debtor::factory()->count(4)->create(['iban_valid' => true]);
        Debtor::factory()->count(1)->create(['iban_valid' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.debtors.valid_iban_rate', 80);
    }

    public function test_dashboard_returns_vop_stats(): void
    {
        VopLog::factory()->count(5)->create(['result' => VopLog::RESULT_VERIFIED]);
        VopLog::factory()->count(3)->create(['result' => VopLog::RESULT_LIKELY_VERIFIED]);
        VopLog::factory()->count(2)->create(['result' => VopLog::RESULT_MISMATCH]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.total', 10)
            ->assertJsonPath('data.vop.by_result.verified', 5)
            ->assertJsonPath('data.vop.by_result.likely_verified', 3)
            ->assertJsonPath('data.vop.by_result.mismatch', 2);
    }

    public function test_dashboard_vop_stats_all_results(): void
    {
        VopLog::factory()->create(['result' => VopLog::RESULT_VERIFIED]);
        VopLog::factory()->create(['result' => VopLog::RESULT_LIKELY_VERIFIED]);
        VopLog::factory()->create(['result' => VopLog::RESULT_INCONCLUSIVE]);
        VopLog::factory()->create(['result' => VopLog::RESULT_MISMATCH]);
        VopLog::factory()->create(['result' => VopLog::RESULT_REJECTED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.total', 5)
            ->assertJsonPath('data.vop.by_result.verified', 1)
            ->assertJsonPath('data.vop.by_result.likely_verified', 1)
            ->assertJsonPath('data.vop.by_result.inconclusive', 1)
            ->assertJsonPath('data.vop.by_result.mismatch', 1)
            ->assertJsonPath('data.vop.by_result.rejected', 1);
    }

    public function test_dashboard_vop_verification_rate(): void
    {
        VopLog::factory()->count(6)->create(['result' => VopLog::RESULT_VERIFIED]);
        VopLog::factory()->count(4)->create(['result' => VopLog::RESULT_LIKELY_VERIFIED]);
        VopLog::factory()->count(5)->create(['result' => VopLog::RESULT_INCONCLUSIVE]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.verification_rate', 66.67);
    }

    public function test_dashboard_vop_average_score(): void
    {
        VopLog::factory()->create(['vop_score' => 50]);
        VopLog::factory()->create(['vop_score' => 76]);
        VopLog::factory()->create(['vop_score' => 99]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.average_score', 75);
    }

    public function test_dashboard_vop_today_count(): void
    {
        VopLog::factory()->count(3)->create(['created_at' => now()]);
        VopLog::factory()->count(2)->create(['created_at' => now()->subDays(1)]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.today', 3);
    }

    public function test_dashboard_vop_verification_rate_with_no_logs(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.verification_rate', 0);
    }

    public function test_dashboard_vop_average_score_with_no_logs(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.average_score', 0);
    }

    public function test_dashboard_returns_billing_stats(): void
    {
        BillingAttempt::factory()->count(5)->create(['status' => BillingAttempt::STATUS_APPROVED, 'amount' => 100]);
        BillingAttempt::factory()->count(2)->create(['status' => BillingAttempt::STATUS_CHARGEBACKED, 'amount' => 50]);
        BillingAttempt::factory()->count(3)->create(['status' => BillingAttempt::STATUS_DECLINED, 'amount' => 75]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.total_attempts', 10)
            ->assertJsonPath('data.billing.by_status.approved', 5)
            ->assertJsonPath('data.billing.by_status.chargebacked', 2)
            ->assertJsonPath('data.billing.by_status.declined', 3);
    }

    public function test_dashboard_billing_stats_all_statuses(): void
    {
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_PENDING]);
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_APPROVED]);
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_DECLINED]);
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_ERROR]);
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_VOIDED]);
        BillingAttempt::factory()->create(['status' => BillingAttempt::STATUS_CHARGEBACKED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.total_attempts', 6)
            ->assertJsonPath('data.billing.by_status.pending', 1)
            ->assertJsonPath('data.billing.by_status.approved', 1)
            ->assertJsonPath('data.billing.by_status.declined', 1)
            ->assertJsonPath('data.billing.by_status.error', 1)
            ->assertJsonPath('data.billing.by_status.voided', 1)
            ->assertJsonPath('data.billing.by_status.chargebacked', 1);
    }

    public function test_dashboard_billing_approval_rate(): void
    {
        BillingAttempt::factory()->count(7)->create(['status' => BillingAttempt::STATUS_APPROVED]);
        BillingAttempt::factory()->count(3)->create(['status' => BillingAttempt::STATUS_DECLINED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.approval_rate', 70);
    }

    public function test_dashboard_billing_chargeback_rate(): void
    {
        // 8 approved, 2 chargebacked => CB/approved = 2/8 = 25%
        BillingAttempt::factory()->count(8)->create(['status' => BillingAttempt::STATUS_APPROVED]);
        BillingAttempt::factory()->count(2)->create(['status' => BillingAttempt::STATUS_CHARGEBACKED]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.chargeback_rate', 25);
    }

    public function test_dashboard_billing_amounts(): void
    {
        BillingAttempt::factory()->count(5)->create(['status' => BillingAttempt::STATUS_APPROVED, 'amount' => 100.50]);
        BillingAttempt::factory()->count(2)->create(['status' => BillingAttempt::STATUS_CHARGEBACKED, 'amount' => 75.25]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.total_approved_amount', 502.5)
            ->assertJsonPath('data.billing.total_chargeback_amount', 150.5);
    }

    public function test_dashboard_billing_today_count(): void
    {
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now(),
        ]);
        BillingAttempt::factory()->count(2)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(1),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.today', 3);
    }

    public function test_dashboard_billing_average_attempts_per_debtor(): void
    {
        $debtors = Debtor::factory()->count(5)->create();

        foreach ($debtors as $debtor) {
            BillingAttempt::factory()->count(3)->create([
                'status' => BillingAttempt::STATUS_APPROVED,
                'debtor_id' => $debtor->id,
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.average_attempts_per_debtor', 3);
    }

    public function test_dashboard_billing_average_attempts_per_debtor_zero_debtors(): void
    {
        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'debtor_id' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.average_attempts_per_debtor', 5);
    }

    public function test_dashboard_returns_recent_activity(): void
    {
        Upload::factory()->count(10)->create();
        BillingAttempt::factory()->count(10)->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data.recent_activity.recent_uploads')
            ->assertJsonCount(5, 'data.recent_activity.recent_billing');
    }

    public function test_dashboard_recent_uploads_structure(): void
    {
        Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'recent_activity' => [
                        'recent_uploads' => [
                            [
                                'id',
                                'original_filename',
                                'status',
                                'total_records',
                                'created_at',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_dashboard_recent_billing_structure(): void
    {
        BillingAttempt::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'recent_activity' => [
                        'recent_billing' => [
                            [
                                'id',
                                'debtor_id',
                                'status',
                                'amount',
                                'emp_created_at',
                                'created_at',
                                'debtor',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_dashboard_recent_activity_sorted_by_latest(): void
    {
        $first = Upload::factory()->create(['created_at' => now()->subDays(5)]);
        $second = Upload::factory()->create(['created_at' => now()->subDays(4)]);
        $third = Upload::factory()->create(['created_at' => now()->subDays(3)]);
        $fourth = Upload::factory()->create(['created_at' => now()->subDays(2)]);
        $fifth = Upload::factory()->create(['created_at' => now()->subDay()]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.recent_activity.recent_uploads.0.id', $fifth->id)
            ->assertJsonPath('data.recent_activity.recent_uploads.1.id', $fourth->id)
            ->assertJsonPath('data.recent_activity.recent_uploads.2.id', $third->id)
            ->assertJsonPath('data.recent_activity.recent_uploads.3.id', $second->id)
            ->assertJsonPath('data.recent_activity.recent_uploads.4.id', $first->id);
    }

    public function test_dashboard_returns_trends(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonCount(7, 'data.trends');
    }

    public function test_dashboard_trends_structure(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'trends' => [
                        [
                            'date',
                            'uploads',
                            'debtors',
                            'billing_attempts',
                            'successful_payments',
                            'chargebacks',
                        ],
                    ],
                ],
            ]);
    }

    public function test_dashboard_trends_covers_last_7_days(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200);
        $trends = $response->json('data.trends');

        $this->assertCount(7, $trends);
        $startDate = now()->subDays(6);
        for ($i = 0; $i < 7; $i++) {
            $expectedDate = $startDate->clone()->addDays($i)->format('Y-m-d');
            $this->assertEquals($expectedDate, $trends[$i]['date']);
        }
    }

    public function test_dashboard_with_month_and_year_filters(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=3&year=2025');

        $response->assertStatus(200)
            ->assertJsonPath('data.filters.month', '3')
            ->assertJsonPath('data.filters.year', '2025');
    }

    public function test_dashboard_debtor_stats_filtered_by_month(): void
    {
        $march = Carbon::create(2025, 3, 15);
        $april = Carbon::create(2025, 4, 15);

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'created_at' => $march,
        ]);
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'created_at' => $april,
        ]);

        $marchResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=3&year=2025');

        $marchResponse->assertStatus(200)
            ->assertJsonPath('data.debtors.total_amount', 500);
    }

    public function test_dashboard_billing_stats_filtered_by_month(): void
    {
        $march = Carbon::create(2025, 3, 15);
        $april = Carbon::create(2025, 4, 15);

        BillingAttempt::factory()->count(5)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => $march,
        ]);
        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => $april,
        ]);

        $marchResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=3&year=2025');

        $marchResponse->assertStatus(200)
            ->assertJsonPath('data.billing.total_attempts', 5);
    }

    public function test_dashboard_uses_emp_created_at_for_date_filtering(): void
    {
        $march = Carbon::create(2025, 3, 15);
        $april = Carbon::create(2025, 4, 15);

        BillingAttempt::factory()->count(3)->create([
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
            'emp_created_at' => $march,
            'created_at' => $april,
        ]);

        $marchResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=3&year=2025');

        $marchResponse->assertStatus(200)
            ->assertJsonPath('data.debtors.total_amount', 300);
    }

    public function test_dashboard_validates_month_parameter(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=13');

        $response->assertStatus(422);
    }

    public function test_dashboard_validates_month_zero(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=0');

        $response->assertStatus(422);
    }

    public function test_dashboard_validates_year_parameter(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?year=2019');

        $response->assertStatus(422);
    }

    public function test_dashboard_validates_year_too_high(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?year=2101');

        $response->assertStatus(422);
    }

    public function test_dashboard_validates_month_non_integer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?month=abc');

        $response->assertStatus(422);
    }

    public function test_dashboard_validates_year_non_integer(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard?year=abc');

        $response->assertStatus(422);
    }

    public function test_dashboard_with_empty_database(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.uploads.total', 0)
            ->assertJsonPath('data.debtors.total', 0)
            ->assertJsonPath('data.vop.total', 0)
            ->assertJsonPath('data.billing.total_attempts', 0);
    }

    public function test_dashboard_handles_division_by_zero_in_rates(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.billing.approval_rate', 0)
            ->assertJsonPath('data.billing.chargeback_rate', 0)
            ->assertJsonPath('data.debtors.recovery_rate', 0)
            ->assertJsonPath('data.vop.verification_rate', 0);
    }

    public function test_dashboard_vop_zero_average_when_all_zero_scores(): void
    {
        VopLog::factory()->count(2)->create(['vop_score' => 0.0]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.vop.average_score', 0);
    }

    public function test_dashboard_response_is_consistent(): void
    {
        Upload::factory()->count(5)->create();
        Debtor::factory()->count(10)->create();

        $response1 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response2 = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $this->assertEquals(
            $response1->json('data.uploads.total'),
            $response2->json('data.uploads.total')
        );
        $this->assertEquals(
            $response1->json('data.debtors.total'),
            $response2->json('data.debtors.total')
        );
    }

    public function test_dashboard_excludes_xt33_and_xt73_from_chargeback_calculations(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 50,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 30,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200);

        $billing = $response->json('data.billing');
        $this->assertEquals(1, $billing['by_status']['chargebacked']);
        $this->assertEquals(100, $billing['total_chargeback_amount']);
        $this->assertEquals(100, $billing['chargeback_rate']); // 1/1 = 100% (formula: chargebacks/(approved_that_became_chargebacks + chargebacks))
    }

    public function test_dashboard_calculates_recovery_metrics_excluding_xt33_xt73(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'amount' => 50,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'amount' => 30,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'amount' => 100,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200);

        $debtors = $response->json('data.debtors');
        $this->assertEquals(200, $debtors['recovered_amount']);
        $this->assertEquals(41.67, $debtors['recovery_rate']);
    }

    public function test_dashboard_trends_exclude_xt33_xt73_chargebacks(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $today = now()->format('Y-m-d');

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'AM04',
            'created_at' => $today,
            'emp_created_at' => $today,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT33',
            'created_at' => $today,
            'emp_created_at' => $today,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'chargeback_reason_code' => 'XT73',
            'created_at' => $today,
            'emp_created_at' => $today,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/dashboard');

        $response->assertStatus(200);

        $trends = $response->json('data.trends');
        $todayTrend = collect($trends)->firstWhere('date', $today);

        $this->assertEquals(1, $todayTrend['chargebacks']);
    }
}
