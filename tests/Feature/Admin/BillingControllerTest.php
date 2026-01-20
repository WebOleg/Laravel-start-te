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
            // CRITICAL: Controller strictly filters for STATUS_UPLOADED
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        // Controller requires VOP to be completed for ALL valid debtors before allowing sync
        foreach ($debtors as $debtor) {
            VopLog::factory()->create([
                'upload_id' => $upload->id,
                'debtor_id' => $debtor->id,
                'name_match' => 'yes',
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

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $validDebtor->id,
            'name_match' => 'yes',
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

        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'name_match' => 'yes',
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

    public function test_sync_skips_debtors_with_vop_name_mismatch(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        // Create a VOP Log with 'no' match
        // The checkVopCompleted passes (because log exists), but the main query filters it out
        VopLog::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'name_match' => 'no',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        // Should return 200 (No eligible)
        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
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
        VopLog::factory()->create(['upload_id' => $upload->id, 'debtor_id' => $flywheelDebtor->id, 'name_match' => 'yes']);

        // 2. Legacy Debtor (Should be excluded)
        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        $legacyDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $legacyProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'iban_valid' => true,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
        VopLog::factory()->create(['upload_id' => $upload->id, 'debtor_id' => $legacyDebtor->id, 'name_match' => 'yes']);

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
}
