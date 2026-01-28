<?php

/**
 * Feature tests for BillingController.
 */

namespace Tests\Feature\Admin;

use App\Jobs\ProcessBillingJob;
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

        // Controller requires VOP to be completed with PASSED result
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

        // 1. Eligible Debtor
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

        // 2. Ineligible Debtor (Invalid status)
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

        // Debtor is valid and has VOP
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

        // BUT: Has a pending billing attempt (Controller: Legacy logic blocks this)
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should find 0 eligible because of the pending attempt
        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0)
            ->assertJsonPath('data.queued', false);
    }

    public function test_sync_prevents_duplicate_dispatch(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();

        // Use 'all' as default debtor type
        $defaultType = DebtorProfile::ALL;

        // Manually set the cache lock that the controller checks
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

        // Debtors are valid, but NO VopLog entries exist
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Controller returns 422 if VOP is not 100% complete for valid rows
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

        // Create a VOP Log with mismatch result (score 20-39)
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_MISMATCH,
            'vop_score' => 35,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should return 200 (No eligible) - mismatch results are excluded
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

        // Create a VOP Log with rejected result
        VopLog::factory()->rejected()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should return 200 (No eligible) - rejected results are excluded
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

        // Create a VOP Log with inconclusive result
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'result' => VopLog::RESULT_INCONCLUSIVE,
            'vop_score' => 45,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should return 200 (No eligible) - inconclusive results are excluded
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

        // Create a VOP Log with likely_verified result (score 60-79)
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

        // Should return 202 - likely_verified is allowed
        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.queued', true);
    }

    public function test_sync_filters_by_specific_billing_model(): void
    {
        Bus::fake();
        $upload = Upload::factory()->create();

        // 1. Flywheel Debtor (Target)
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

        // 2. Legacy Debtor (Should be excluded)
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

        // Request ONLY Flywheel
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync", [
                'debtor_type' => DebtorProfile::MODEL_FLYWHEEL
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.eligible', 1)
            ->assertJsonPath('data.model', DebtorProfile::MODEL_FLYWHEEL);

        // Assert job was dispatched with correct model
        Bus::assertDispatched(ProcessBillingJob::class, function ($job) {
            return $job->billingModel === DebtorProfile::MODEL_FLYWHEEL;
        });
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

        // Create Flywheel debtor with attempts
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

        // Create Recovery debtor with attempts
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

        // Request Flywheel only
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

        // Manually set the cache key to simulate ongoing billing
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

        // Request without debtor_type parameter
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.filter_type', DebtorProfile::ALL)
            ->assertJsonPath('data.total_attempts', 5);
    }
}
