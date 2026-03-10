<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessBillingJob;
use App\Jobs\VoidUploadJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\User;
use App\Models\VopLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BillingResyncControllerTest extends TestCase
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

    // ──────────────────────────────────────────────
    // Cancel endpoint: POST /api/admin/billing/{upload}/cancel
    // ──────────────────────────────────────────────

    public function test_cancel_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(401);
    }

    public function test_cancel_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/billing/99999/cancel');

        $response->assertStatus(404);
    }

    public function test_cancel_resync_when_resync_is_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        // Simulate active resync lock
        Cache::put("billing_resync_{$upload->id}", true, 300);
        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY, true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync', true)
            ->assertJsonPath('data.billing_status', Upload::STATUS_CANCELLING);

        // Verify resync locks were cleared
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
        $this->assertFalse(Cache::has("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_LEGACY));

        // Verify kill switch is set
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));

        // Verify upload status updated
        $upload->refresh();
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->billing_status);
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->status);
    }

    public function test_cancel_normal_sync_when_no_resync_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync', false)
            ->assertJsonPath('data.billing_status', Upload::STATUS_CANCELLING);

        // Verify kill switch is set
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));

        // Verify upload status updated
        $upload->refresh();
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->billing_status);
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->status);
    }

    public function test_cancel_response_includes_signal_sent_at(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['upload_id', 'billing_status', 'is_resync', 'signal_sent_at'],
            ]);
    }

    // ──────────────────────────────────────────────
    // Void endpoint: POST /api/admin/billing/{upload}/void
    // ──────────────────────────────────────────────

    public function test_void_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(401);
    }

    public function test_void_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/billing/99999/void');

        $response->assertStatus(404);
    }

    public function test_void_rejects_transactions_older_than_24_hours(): void
    {
        $upload = Upload::factory()->create([
            'billing_completed_at' => now()->subHours(25),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot void transactions older than 24 hours. Please use Refund instead.']);
    }

    public function test_void_rejects_when_no_eligible_transactions(): void
    {
        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
        ]);

        // No billing attempts at all
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No eligible transactions found to void.']);
    }

    public function test_void_dispatches_job_for_eligible_transactions(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_void_1',
        ]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_void_2',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.queued_count', 2);

        Bus::assertDispatched(VoidUploadJob::class, function ($job) use ($upload) {
            return $job->upload->id === $upload->id;
        });
    }

    public function test_void_updates_upload_status_to_voiding(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_1',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $upload->refresh();
        $this->assertEquals(Upload::STATUS_VOIDING, $upload->billing_status);
        $this->assertEquals(Upload::STATUS_VOIDING, $upload->status);
    }

    public function test_void_returns_is_resync_flag_based_on_billing_runs(): void
    {
        Bus::fake();

        // Upload with prior billing runs (resync scenario)
        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
            'billing_runs' => [['run' => 1, 'status' => 'completed']],
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.is_resync', true);
    }

    public function test_void_returns_is_resync_false_when_no_billing_runs(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
            'billing_runs' => null,
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.is_resync', false);
    }

    public function test_void_excludes_declined_and_error_attempts(): void
    {
        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(1),
            'billing_completed_at' => now()->subMinutes(30),
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        // Only non-voidable attempts
        BillingAttempt::factory()->declined()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_declined',
        ]);

        BillingAttempt::factory()->error()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_error',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No eligible transactions found to void.']);
    }

    // ──────────────────────────────────────────────
    // Resync via sync endpoint: POST /api/admin/uploads/{upload}/sync
    // (is_30d_cool = false triggers resync path)
    // ──────────────────────────────────────────────

    public function test_sync_resync_path_triggers_when_cooldown_off(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonPath('data.model', DebtorProfile::MODEL_LEGACY)
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(ProcessBillingJob::class);
    }

    public function test_sync_resync_returns_denial_when_ineligible(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subHours(2),
            'billing_completed_at' => now()->subHours(1), // Cooldown not elapsed
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'data' => ['resync_count', 'max_resync']]);
    }

    public function test_sync_resync_returns_409_when_already_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        // Set the resync lock
        Cache::put("billing_resync_{$upload->id}", true, 300);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(409);
    }

    public function test_sync_resync_response_includes_resync_data(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_old',
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'data' => ['upload_id', 'eligible', 'reset_count', 'archived', 'queued', 'model'],
            ])
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.model', DebtorProfile::MODEL_LEGACY);
    }

    public function test_sync_blocks_when_upload_is_voiding(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::STATUS_VOIDING,
            'status' => Upload::STATUS_VOIDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot start billing while upload is voiding or has been cancelled.']);
    }

    public function test_sync_blocks_when_upload_is_cancelled(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::STATUS_CANCELLED,
            'status' => Upload::STATUS_CANCELLED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Cannot start billing while upload is voiding or has been cancelled.']);
    }

    public function test_sync_returns_422_for_invalid_billing_model(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => 'invalid_model',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'Invalid billing model provided']);
    }

    public function test_sync_resync_rejects_non_legacy_model(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
        ]);

        // VOP completed (no eligible debtors with iban_valid)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => DebtorProfile::MODEL_FLYWHEEL,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('data.queued', false);
    }

    // ──────────────────────────────────────────────
    // Integration: Full resync lifecycle
    // ──────────────────────────────────────────────

    public function test_full_resync_lifecycle_sync_complete_resync_archives_run(): void
    {
        Bus::fake();

        // Step 1: Create an upload that completed its first billing run
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_batch_id' => 'batch_run1',
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_FAILED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        // Create a declined attempt from the first run
        BillingAttempt::factory()->declined()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        // Step 2: Trigger resync
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202);

        // Step 3: Verify run was archived
        $upload->refresh();
        $runs = $upload->billing_runs;

        $this->assertCount(1, $runs);
        $this->assertEquals(1, $runs[0]['run']);
        $this->assertEquals('batch_run1', $runs[0]['batch_id']);

        // Step 4: Verify debtor was reset to uploaded
        $debtor->refresh();
        $this->assertEquals(Debtor::STATUS_UPLOADED, $debtor->status);

        // Step 5: Verify job was dispatched
        Bus::assertDispatched(ProcessBillingJob::class);
    }

    public function test_resync_cap_enforcement_fourth_resync_denied(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
            'billing_runs' => [
                ['run' => 1, 'status' => 'completed'],
                ['run' => 2, 'status' => 'completed'],
                ['run' => 3, 'status' => 'completed'],
            ],
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonPath('data.resync_count', 3)
            ->assertJsonPath('data.max_resync', Upload::MAX_RESYNC_ATTEMPTS);
    }

    public function test_cancel_during_resync_then_retry(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
            'billing_started_at' => now()->subDays(10),
            'billing_completed_at' => now()->subDays(6),
        ]);

        // Set active resync lock
        Cache::put("billing_resync_{$upload->id}", true, 300);

        // Cancel the resync
        $cancelResponse = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJsonPath('data.is_resync', true);

        // Verify locks are cleared
        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));

        // Verify upload shows cancelling
        $upload->refresh();
        $this->assertEquals(Upload::STATUS_CANCELLING, $upload->billing_status);
    }

    public function test_void_after_resync_scopes_to_latest_run(): void
    {
        Bus::fake();

        $billingStartedAt = now()->subHours(2);

        $upload = Upload::factory()->create([
            'billing_started_at' => $billingStartedAt,
            'billing_completed_at' => now()->subHours(1),
            'billing_runs' => [['run' => 1, 'status' => 'completed']],
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        // Old attempt from run 1 — should NOT be voidable
        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_old',
            'created_at' => $billingStartedAt->copy()->subDays(3),
        ]);

        // New attempt from latest run — should be voidable
        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_new',
            'created_at' => $billingStartedAt->copy()->addMinutes(15),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.queued_count', 1);
    }

    // ──────────────────────────────────────────────
    // Stats endpoint: resync detection
    // ──────────────────────────────────────────────

    public function test_stats_detects_resync_processing_state(): void
    {
        $upload = Upload::factory()->create();

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync_processing', true);
    }

    public function test_stats_shows_no_resync_processing_when_no_lock(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync_processing', false);
    }
}
