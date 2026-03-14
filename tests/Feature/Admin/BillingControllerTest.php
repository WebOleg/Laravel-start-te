<?php

/**
 * Feature tests for BillingController.
 */

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

class BillingControllerTest extends TestCase
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

    public function test_sync_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(401);
    }

    public function test_sync_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/uploads/99999/sync');

        $response->assertStatus(404);
    }

    public function test_sync_returns_no_eligible_when_no_debtors(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0)
            ->assertJsonPath('data.queued', false);
    }

    public function test_sync_dispatches_job_for_eligible_debtors(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtors = Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        foreach ($debtors as $debtor) {
            VopLog::factory()->verified()->create([
                'upload_id' => $upload->id,
                'debtor_id' => $debtor->id,
            ]);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 3)
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(ProcessBillingJob::class, function ($job) use ($upload) {
            return $job->upload->id === $upload->id;
        });
    }

    public function test_sync_skips_invalid_debtors(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        $validDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $validDebtor->id,
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'iban_valid' => false,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 1);
    }

    public function test_sync_skips_debtors_with_pending_billing(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0)
            ->assertJsonPath('data.queued', false);
    }

    public function test_sync_prevents_duplicate_dispatch(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        $defaultType = DebtorProfile::ALL;

        Cache::put("billing_sync_{$upload->id}_{$defaultType}", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(409)
            ->assertJsonPath('data.duplicate', true);

        Bus::assertNotDispatched(ProcessBillingJob::class);
    }

    public function test_sync_blocked_without_vop_verification(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonPath('data.vop_required', true)
            ->assertJsonPath('data.vop_pending', 3);

        Bus::assertNotDispatched(ProcessBillingJob::class);
    }

    public function test_sync_skips_debtors_with_vop_mismatch_result(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
            'vop_score' => 35,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
    }

    public function test_sync_skips_debtors_with_vop_rejected_result(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->rejected()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
    }

    public function test_sync_skips_debtors_with_vop_inconclusive_result(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_INCONCLUSIVE,
            'vop_score' => 45,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
    }

    public function test_sync_allows_likely_verified_result(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_LIKELY_VERIFIED,
            'vop_score' => 65,
            'iban_valid' => true,
            'bank_identified' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.queued', true);
    }

    public function test_sync_filters_by_specific_billing_model(): void
    {
        Bus::fake();
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheelDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $flywheelDebtor->id,
        ]);

        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        $legacyDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $legacyProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $legacyDebtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => DebtorProfile::MODEL_FLYWHEEL
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.model', DebtorProfile::MODEL_FLYWHEEL);

        Bus::assertDispatched(ProcessBillingJob::class, function ($job) {
            return $job->billingModel === DebtorProfile::MODEL_FLYWHEEL;
        });
    }

    // ──────────────────────────────────────────────
    // sync() — New: Invalid Billing Model
    // ──────────────────────────────────────────────

    public function test_sync_rejects_invalid_billing_model(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => 'nonexistent_model',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Invalid billing model provided');
    }

    // ──────────────────────────────────────────────
    // sync() — New: Voiding / Cancelled Blocking
    // ──────────────────────────────────────────────

    public function test_sync_blocked_when_upload_is_voiding(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::STATUS_VOIDING,
            'status' => Upload::STATUS_VOIDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonPath('data.billing_status', Upload::STATUS_VOIDING);
    }

    public function test_sync_blocked_when_upload_is_cancelled(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::STATUS_CANCELLED,
            'status' => Upload::STATUS_CANCELLED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────
    // sync() — New: Resync Mode (is_30d_cool = false)
    // ──────────────────────────────────────────────

    public function test_sync_delegates_to_resync_service_when_30d_cool_off(): void
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
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.model', DebtorProfile::MODEL_LEGACY);
    }

    public function test_sync_resync_returns_denial_when_cooldown_active(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => now()->subHours(2),
            'billing_completed_at' => now()->subHours(1),
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
            'billing_model' => DebtorProfile::MODEL_LEGACY,
        ]);

        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => Debtor::first()->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonPath('data.queued', false);
    }

    public function test_sync_resync_returns_409_when_lock_active(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);

        // Need VOP to pass the check
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
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

    public function test_sync_resync_returns_409_when_billing_processing(): void
    {
        $upload = Upload::factory()->create([
            'is_30d_cool' => false,
            'billing_status' => Upload::JOB_PROCESSING,
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
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

    // ──────────────────────────────────────────────
    // sync() — New: Duplicate Lock Per Model
    // ──────────────────────────────────────────────

    public function test_sync_duplicate_check_is_model_specific(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        // Lock flywheel, but request recovery
        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_FLYWHEEL, true, 300);

        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $recoveryProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => DebtorProfile::MODEL_RECOVERY,
            ]);

        // Should NOT be blocked — different model lock
        $response->assertStatus(202);
    }

    // ──────────────────────────────────────────────
    // sync() — New: VOP Check Edge Cases
    // ──────────────────────────────────────────────

    public function test_sync_passes_vop_check_when_no_eligible_debtors(): void
    {
        $upload = Upload::factory()->create();

        // No valid debtors at all — VOP check should pass (0 eligible)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should get 200 (no eligible), not 422 (VOP required)
        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
    }

    public function test_sync_vop_check_counts_partial_verification(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        // 2 valid debtors
        $debtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        // Only 1 verified
        VopLog::factory()->verified()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor1->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(422)
            ->assertJsonPath('data.vop_required', true)
            ->assertJsonPath('data.vop_pending', 1)
            ->assertJsonPath('data.vop_verified', 1)
            ->assertJsonPath('data.vop_total_eligible', 2);
    }

    // ──────────────────────────────────────────────
    // sync() — New: Response Data Structure
    // ──────────────────────────────────────────────

    public function test_sync_response_includes_model_in_data(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
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
            ->assertJsonPath('data.model', DebtorProfile::ALL)
            ->assertJsonPath('data.upload_id', $upload->id);
    }

    public function test_stats_returns_billing_statistics(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.00,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 50.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.upload_id', $upload->id)
            ->assertJsonPath('data.total_attempts', 5)
            ->assertJsonPath('data.approved', 3)
            ->assertJsonPath('data.declined', 2);
    }

    public function test_billing_stats_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(401);
    }

    public function test_billing_stats_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/99999/billing-stats');

        $response->assertStatus(404);
    }

    public function test_billing_stats_with_all_statuses(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.00,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'amount' => 75.50,
        ]);

        BillingAttempt::factory()->count(1)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'amount' => 50.00,
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_ERROR,
            'amount' => 25.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.upload_id', $upload->id)
            ->assertJsonPath('data.total_attempts', 8)
            ->assertJsonPath('data.approved', 3)
            ->assertJsonPath('data.approved_amount', 300)
            ->assertJsonPath('data.pending', 2)
            ->assertJsonPath('data.pending_amount', 151)
            ->assertJsonPath('data.declined', 1)
            ->assertJsonPath('data.declined_amount', 50)
            ->assertJsonPath('data.error', 2)
            ->assertJsonPath('data.error_amount', 50);
    }

    public function test_billing_stats_with_empty_attempts(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.upload_id', $upload->id)
            ->assertJsonPath('data.total_attempts', 0)
            ->assertJsonPath('data.approved', 0)
            ->assertJsonPath('data.approved_amount', 0)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.pending_amount', 0)
            ->assertJsonPath('data.declined', 0)
            ->assertJsonPath('data.declined_amount', 0)
            ->assertJsonPath('data.error', 0)
            ->assertJsonPath('data.error_amount', 0);
    }

    public function test_billing_stats_filters_by_debtor_type(): void
    {
        $upload = Upload::factory()->create();

        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        $flywheelDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $flywheelDebtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'billing_model' => DebtorProfile::MODEL_FLYWHEEL,
            'amount' => 100.00,
        ]);

        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        $recoveryDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $recoveryDebtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'billing_model' => DebtorProfile::MODEL_RECOVERY,
            'amount' => 100.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats?debtor_type=" . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200)
            ->assertJsonPath('data.filter_type', DebtorProfile::MODEL_FLYWHEEL)
            ->assertJsonPath('data.total_attempts', 5)
            ->assertJsonPath('data.approved', 5)
            ->assertJsonPath('data.approved_amount', 500);
    }

    public function test_billing_stats_detects_processing_state(): void
    {
        $upload = Upload::factory()->create();

        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::ALL, true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true);
    }

    public function test_billing_stats_no_processing_when_cache_expired(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false);
    }

    public function test_billing_stats_includes_billing_status_timestamps(): void
    {
        $startTime = now();
        $endTime = now()->addHours(2);

        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
            'billing_started_at' => $startTime,
            'billing_completed_at' => $endTime,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.billing_status', Upload::JOB_COMPLETED)
            ->assertJsonPath('data.billing_started_at', $startTime->toIso8601String())
            ->assertJsonPath('data.billing_completed_at', $endTime->toIso8601String());
    }

    public function test_billing_stats_default_debtor_type_is_all(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'amount' => 100.00,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.filter_type', DebtorProfile::ALL)
            ->assertJsonPath('data.total_attempts', 5);
    }

    public function test_billing_stats_detects_resync_processing(): void
    {
        $upload = Upload::factory()->create();

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync_processing', true);
    }

    public function test_billing_stats_resync_not_processing_when_no_cache(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync_processing', false);
    }

    public function test_billing_stats_detects_model_specific_processing(): void
    {
        $upload = Upload::factory()->create();

        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_FLYWHEEL, true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats?debtor_type=" . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true);
    }

    public function test_billing_stats_not_processing_for_different_model(): void
    {
        $upload = Upload::factory()->create();

        // Flywheel is processing, but we query recovery
        Cache::put("billing_sync_{$upload->id}_" . DebtorProfile::MODEL_FLYWHEEL, true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats?debtor_type=" . DebtorProfile::MODEL_RECOVERY);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false);
    }

    public function test_billing_stats_all_type_detects_any_model_processing(): void
    {
        $upload = Upload::factory()->create();

        // Only legacy is processing
        Cache::put("billing_sync_{$upload->id}_legacy", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true);
    }

    public function test_billing_stats_handles_null_timestamps(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_IDLE,
            'billing_started_at' => null,
            'billing_completed_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.billing_started_at', null)
            ->assertJsonPath('data.billing_completed_at', null);
    }

    public function test_void_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(401);
    }

    public function test_void_rejects_when_older_than_24_hours(): void
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
            'billing_completed_at' => now()->subHours(2),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No eligible transactions found to void.']);
    }

    public function test_void_dispatches_job_and_updates_status(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(2),
            'billing_completed_at' => now()->subHours(1),
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_void_1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.queued_count', 1);

        Bus::assertDispatched(VoidUploadJob::class);

        $upload->refresh();
        $this->assertEquals(Upload::STATUS_VOIDING, $upload->billing_status);
        $this->assertEquals(Upload::STATUS_VOIDING, $upload->status);
    }

    public function test_void_response_includes_resync_flag(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'billing_started_at' => now()->subHours(2),
            'billing_completed_at' => now()->subHours(1),
            'billing_runs' => [['run' => 1, 'status' => 'completed']],
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->approved()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'tx_void_resync',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/void");

        $response->assertStatus(202)
            ->assertJsonPath('data.is_resync', true);
    }

    public function test_cancel_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(401);
    }

    public function test_cancel_rejects_when_no_active_billing(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_COMPLETED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'No active billing to cancel.']);
    }

    public function test_cancel_succeeds_when_billing_is_processing(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.billing_status', Upload::STATUS_CANCELLING)
            ->assertJsonPath('data.is_resync', false);

        $this->assertTrue(Cache::has("billing_sync_stop_{$upload->id}"));
    }

    public function test_cancel_delegates_to_resync_service_when_resync_in_progress(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        Cache::put("billing_resync_{$upload->id}", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_resync', true);

        $this->assertFalse(Cache::has("billing_resync_{$upload->id}"));
    }

    public function test_cancel_clears_all_sync_locks(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            Cache::put("billing_sync_{$upload->id}_{$model}", true, 300);
        }

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200);

        foreach (['all', 'legacy', 'flywheel', 'recovery'] as $model) {
            $this->assertFalse(Cache::has("billing_sync_{$upload->id}_{$model}"));
        }
    }

    public function test_cancel_allowed_when_already_cancelling(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::STATUS_CANCELLING,
            'status' => Upload::STATUS_CANCELLING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.billing_status', Upload::STATUS_CANCELLING);
    }

    public function test_cancel_response_includes_timestamp(): void
    {
        $upload = Upload::factory()->create([
            'billing_status' => Upload::JOB_PROCESSING,
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/billing/{$upload->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['signal_sent_at']]);
    }
}
