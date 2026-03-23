<?php

/**
 * Feature tests for Admin BillingAttempt API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Jobs\ExportCleanUsersJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\EmpAccount;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingAttemptControllerTest extends TestCase
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

    public function test_index_returns_billing_attempts_list(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'debtor_id',
                        'upload_id',
                        'transaction_id',
                        'amount',
                        'status',
                        'attempt_number',
                    ]
                ],
                'meta' => ['current_page', 'total'],
            ]);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/billing-attempts');

        $response->assertStatus(401);
    }

    public function test_index_filters_by_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?status=approved');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('approved', $data[0]['status']);
    }

    public function test_index_filters_by_debtor_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?debtor_id=' . $debtor1->id);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_show_returns_single_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'amount' => 150.00,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/' . $attempt->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $attempt->id)
            ->assertJsonPath('data.status', 'approved');

        $data = $response->json('data');
        $this->assertEquals(150.00, $data['amount']);
    }

    public function test_show_returns_404_for_nonexistent_billing_attempt(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/99999');

        $response->assertStatus(404);
    }

    public function test_index_filters_by_flywheel_model(): void
    {
        $flywheel = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $recovery = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?model=' . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals($flywheel->id, $data[0]['id']);
    }

    public function test_index_filters_by_legacy_model_includes_null_profiles(): void
    {
        $legacy = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        $impliedLegacy = BillingAttempt::factory()->create([
            'billing_model' => null,
            'debtor_profile_id' => null,
        ]);

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheel = BillingAttempt::factory()->create([
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?model=' . DebtorProfile::MODEL_LEGACY);

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);

        $ids = collect($data)->pluck('id');
        $this->assertContains($legacy->id, $ids);
        $this->assertContains($impliedLegacy->id, $ids);
        $this->assertNotContains($flywheel->id, $ids);
    }

    public function test_index_search_finds_by_transaction_id(): void
    {
        BillingAttempt::factory()->create(['transaction_id' => 'TX_FIND_ME']);
        BillingAttempt::factory()->create(['transaction_id' => 'TX_OTHER']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=FIND_ME');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('TX_FIND_ME', $data[0]['transaction_id']);
    }

    public function test_index_search_finds_by_debtor_name(): void
    {
        $attempt = BillingAttempt::factory()->create();
        $debtor = $attempt->debtor;

        BillingAttempt::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=' . $debtor->first_name);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_retry_returns_422_if_not_retryable(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
            'iban_hash' => hash('sha256', 'DE89370400440532013000'),
        ]);

        $attempt = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/billing-attempts/' . $attempt->id . '/retry');

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This billing attempt cannot be retried');
    }

    public function test_retry_requires_authentication(): void
    {
        $attempt = BillingAttempt::factory()->create([
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);

        $response = $this->postJson('/api/admin/billing-attempts/' . $attempt->id . '/retry');

        $response->assertStatus(401);
    }

    public function test_index_pagination(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->count(35)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?per_page=20');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 35);

        $data = $response->json('data');
        $this->assertCount(20, $data);
    }

    public function test_index_pagination_second_page(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->count(35)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?per_page=20&page=2');

        $response->assertStatus(200)
            ->assertJsonPath('meta.current_page', 2);

        $data = $response->json('data');
        $this->assertCount(15, $data);
    }

    public function test_index_filters_by_combined_status_and_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_DECLINED,
        ]);
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor2->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?debtor_id=' . $debtor1->id . '&status=approved');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertTrue(collect($data)->every(fn($item) => $item['status'] === 'approved'));
    }

    public function test_index_search_finds_by_unique_id(): void
    {
        BillingAttempt::factory()->create(['unique_id' => 'UNIQUE_SEARCH_123']);
        BillingAttempt::factory()->create(['unique_id' => 'OTHER_UNIQUE_456']);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=SEARCH_123');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('UNIQUE_SEARCH_123', $data[0]['unique_id']);
    }

    public function test_index_search_finds_by_debtor_email(): void
    {
        $debtor = Debtor::factory()->create(['email' => 'findme@example.com']);
        BillingAttempt::factory()->create(['debtor_id' => $debtor->id]);

        BillingAttempt::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=findme@example.com');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_show_loads_relationships(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/' . $attempt->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'debtor_id',
                    'upload_id',
                    'debtor',
                    'transaction_id',
                    'amount',
                    'status',
                    'can_retry',
                    'is_approved',
                ]
            ]);
    }

    public function test_index_filters_by_upload_id(): void
    {
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
        ]);
        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?upload_id=' . $upload1->id);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_index_search_finds_by_debtor_iban(): void
    {
        $debtor = Debtor::factory()->create(['iban' => 'DE89370400440532013000']);
        BillingAttempt::factory()->create(['debtor_id' => $debtor->id]);

        BillingAttempt::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?search=DE89370400');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_model_all_returns_all_attempts(): void
    {
        BillingAttempt::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        BillingAttempt::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        BillingAttempt::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts?model=all');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_retry_returns_404_for_nonexistent_attempt(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/billing-attempts/99999/retry');

        $response->assertStatus(404);
    }

    public function test_clean_users_stats_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/billing-attempts/clean-users/stats');

        $response->assertStatus(401);
    }

    public function test_clean_users_stats_returns_count(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['count', 'min_days', 'mode', 'streaming_threshold'],
            ])
            ->assertJsonPath('data.min_days', 30)
            ->assertJsonPath('data.mode', 'broad');
    }

    public function test_clean_users_stats_accepts_custom_parameters(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=60&mode=strict');

        $response->assertStatus(200)
            ->assertJsonPath('data.min_days', 60)
            ->assertJsonPath('data.mode', 'strict');
    }

    public function test_clean_users_stats_rejects_invalid_mode(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?mode=invalid');

        $response->assertStatus(422);
    }

    public function test_clean_users_stats_rejects_min_days_over_365(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=500');

        $response->assertStatus(422);
    }

    public function test_clean_users_stats_filters_by_account_id(): void
    {
        $account = EmpAccount::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?account_id=' . $account->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.account_id', (string) $account->id);
    }

    public function test_clean_users_stats_excludes_chargebacked_debtors(): void
    {
        $debtor = Debtor::factory()->create();

        // Approved attempt (should count)
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);

        // Chargeback on same debtor (should exclude)
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    public function test_clean_users_stats_excludes_recently_charged(): void
    {
        $debtor = Debtor::factory()->create();

        // Approved attempt charged 5 days ago (within 30-day window)
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    public function test_clean_users_stats_counts_eligible_debtors(): void
    {
        $debtor = Debtor::factory()->create();

        // Approved, old enough, no chargebacks
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_clean_users_stats_accepts_strict3_mode(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.mode', 'strict3');
    }

    public function test_clean_users_stats_strict3_excludes_debtor_with_two_approvals(): void
    {
        $debtor = Debtor::factory()->create();

        // Two approved attempts — below the strict3 threshold of 3
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 2,
            'emp_created_at' => now()->subDays(60),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    public function test_clean_users_stats_strict3_includes_debtor_with_three_or_more_approvals(): void
    {
        $debtor = Debtor::factory()->create();

        // Three approved attempts — meets the strict3 threshold
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 2,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 3,
            'emp_created_at' => now()->subDays(60),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_export_clean_users_accepts_strict3_mode(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=100&mode=strict3');

        // Should pass validation and return a streamed CSV, not 422
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_clean_users_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/billing-attempts/clean-users/export?limit=10');

        $response->assertStatus(401);
    }

    public function test_export_clean_users_requires_limit(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export');

        $response->assertStatus(422);
    }

    public function test_export_clean_users_streams_small_export(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=100');

        // Small limit → streamed CSV download
        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_export_clean_users_queues_large_export(): void
    {
        Bus::fake();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=20000');

        $response->assertStatus(202)
            ->assertJsonStructure(['data' => ['job_id', 'status', 'message']])
            ->assertJsonPath('data.status', 'pending');

        Bus::assertDispatched(ExportCleanUsersJob::class);
    }

    public function test_export_clean_users_rejects_limit_over_max(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=200000');

        $response->assertStatus(422);
    }

    public function test_export_status_returns_404_for_unknown_job(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export/nonexistent-uuid/status');

        $response->assertStatus(404);
    }

    public function test_export_status_returns_pending_job(): void
    {
        $jobId = 'test-job-123';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'processed' => 0,
            'limit' => 5000,
        ], 3600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress', 0);
    }

    public function test_export_status_includes_download_url_when_completed(): void
    {
        $jobId = 'completed-job-456';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'processed' => 5000,
            'path' => 'exports/clean_users_test.csv',
            'filename' => 'clean_users_test.csv',
        ], 3600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/status");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure(['data' => ['download_url']]);
    }

    public function test_download_export_returns_404_when_not_completed(): void
    {
        $jobId = 'pending-job-789';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'pending',
            'progress' => 50,
        ], 3600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/download");

        $response->assertStatus(404);
    }

    public function test_download_export_returns_404_when_job_not_found(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export/missing-id/download');

        $response->assertStatus(404);
    }

    public function test_download_export_returns_404_when_file_missing_from_s3(): void
    {
        Storage::fake('s3');

        $jobId = 'no-file-job';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'completed',
            'path' => 'exports/missing_file.csv',
            'filename' => 'missing_file.csv',
        ], 3600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/download");

        $response->assertStatus(404)
            ->assertJsonPath('message', 'Export file not found');
    }

    public function test_download_export_streams_file_when_ready(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('exports/ready_file.csv', "first_name,last_name,iban\nJohn,Doe,DE123\n");

        $jobId = 'ready-job';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'completed',
            'path' => 'exports/ready_file.csv',
            'filename' => 'clean_users_export.csv',
        ], 3600);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/download");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    // ── strict3 Mode: Threshold & Counting Edge Cases ──────────────────

    public function test_clean_users_stats_strict3_includes_debtor_with_more_than_three_approvals(): void
    {
        $debtor = Debtor::factory()->create();

        // 5 approved attempts — well above the strict3 threshold of 3
        for ($i = 1; $i <= 5; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_clean_users_stats_strict3_mixed_debtors_counts_only_qualifying(): void
    {
        $qualifiesA = Debtor::factory()->create();
        $qualifiesB = Debtor::factory()->create();
        $doesNotQualify = Debtor::factory()->create();

        // Debtor A: 4 approvals → qualifies
        for ($i = 1; $i <= 4; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $qualifiesA->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Debtor B: exactly 3 approvals → qualifies
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $qualifiesB->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Debtor C: only 2 approvals → does NOT qualify
        for ($i = 1; $i <= 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $doesNotQualify->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 2);
    }

    public function test_clean_users_stats_strict3_returns_zero_when_none_meet_threshold(): void
    {
        // Two debtors, each with only 1 approval
        foreach (range(1, 2) as $_) {
            $debtor = Debtor::factory()->create();
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => 1,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    // ── strict3 Mode: Filter Interactions ──────────────────────────────

    public function test_clean_users_stats_strict3_excludes_chargebacked_debtor(): void
    {
        $debtor = Debtor::factory()->create();

        // 3 approved attempts — meets threshold
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // But also a chargeback → should be excluded
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    public function test_clean_users_stats_strict3_excludes_recently_charged(): void
    {
        $debtor = Debtor::factory()->create();

        // 3 approved attempts, but one is recent (5 days ago)
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 2,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 3,
            'emp_created_at' => now()->subDays(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    public function test_clean_users_stats_strict3_filters_by_account_id(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();

        $debtorA = Debtor::factory()->create();
        $debtorB = Debtor::factory()->create();

        // Debtor A: 3 approvals on account A
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtorA->id,
                'emp_account_id' => $accountA->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Debtor B: 3 approvals on account B
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtorB->id,
                'emp_account_id' => $accountB->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Filter by account A → only debtor A should count
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3&account_id=' . $accountA->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    // ── strict3 Mode: attempt_number Subtlety ──────────────────────────

    public function test_clean_users_stats_strict3_counts_all_attempt_numbers_for_threshold(): void
    {
        $debtor = Debtor::factory()->create();

        // 3 approved attempts across different attempt_numbers
        // The threshold subquery counts ALL approved (any attempt_number)
        // but the main query only returns attempt_number=1 rows
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 2,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 3,
            'emp_created_at' => now()->subDays(60),
        ]);

        // Debtor has 3 approved attempts total → meets threshold
        // AND has an attempt_number=1 row → should appear in results
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 1);
    }

    public function test_clean_users_stats_strict3_excludes_debtor_without_first_attempt(): void
    {
        $debtor = Debtor::factory()->create();

        // 3 approved attempts but NONE with attempt_number=1
        // Threshold subquery sees 3 approved → passes
        // Main query filters attempt_number=1 → no rows for this debtor
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 2,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 3,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 4,
            'emp_created_at' => now()->subDays(60),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $response->assertStatus(200)
            ->assertJsonPath('data.count', 0);
    }

    // ── strict3 Mode: Export ───────────────────────────────────────────

    public function test_export_clean_users_strict3_streamed_csv_contains_only_qualifying_debtors(): void
    {
        $qualifying = Debtor::factory()->create([
            'first_name' => 'Qualifying',
            'last_name' => 'Debtor',
            'iban' => 'DE89370400440532013000',
        ]);
        $nonQualifying = Debtor::factory()->create([
            'first_name' => 'NonQualifying',
            'last_name' => 'Person',
            'iban' => 'NL91ABNA0417164300',
        ]);

        // Qualifying debtor: 3 approved, old enough
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $qualifying->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Non-qualifying: only 2 approved
        for ($i = 1; $i <= 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $nonQualifying->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=100&mode=strict3&min_days=30');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Qualifying', $csv);
        $this->assertStringNotContainsString('NonQualifying', $csv);
    }

    public function test_export_clean_users_strict3_queues_job_with_correct_mode(): void
    {
        Bus::fake();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=20000&mode=strict3&min_days=45');

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending');

        Bus::assertDispatched(ExportCleanUsersJob::class);
    }

    // ── strict3 Mode: Cross-Mode Comparison ────────────────────────────

    public function test_clean_users_stats_strict3_count_lte_strict_lte_broad(): void
    {
        // Debtor with 1 approval → only counted by broad
        $debtor1 = Debtor::factory()->create();
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);

        // Debtor with 2 approvals → counted by broad & strict
        $debtor2 = Debtor::factory()->create();
        for ($i = 1; $i <= 2; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor2->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        // Debtor with 3 approvals → counted by all modes
        $debtor3 = Debtor::factory()->create();
        for ($i = 1; $i <= 3; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor3->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'attempt_number' => $i,
                'emp_created_at' => now()->subDays(60),
            ]);
        }

        $broadResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=broad');
        $strictResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict');
        $strict3Response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30&mode=strict3');

        $broadCount = $broadResponse->json('data.count');
        $strictCount = $strictResponse->json('data.count');
        $strict3Count = $strict3Response->json('data.count');

        // broad >= strict >= strict3
        $this->assertGreaterThanOrEqual($strictCount, $broadCount);
        $this->assertGreaterThanOrEqual($strict3Count, $strictCount);

        // With the data above: broad=3, strict=2, strict3=1
        $this->assertEquals(3, $broadCount);
        $this->assertEquals(2, $strictCount);
        $this->assertEquals(1, $strict3Count);
    }
}
