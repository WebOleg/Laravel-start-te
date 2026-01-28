<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UploadValidationStatsTest extends TestCase
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

    public function test_validation_stats_returns_complete_structure(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'total',
                    'valid',
                    'invalid',
                    'pending',
                    'blacklisted',
                    'chargebacked',
                    'ready_for_sync',
                    'skipped',
                    'is_processing',
                    'model_counts' => [
                        'all',
                        'legacy',
                        'flywheel',
                        'recovery',
                    ]
                ]
            ]);
    }

    public function test_validation_stats_counts_valid_debtors(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.valid', 3)
            ->assertJsonPath('data.invalid', 2);
    }

    public function test_validation_stats_counts_pending_debtors(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(4)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.pending', 4);
    }

    public function test_validation_stats_counts_blacklisted_debtors(): void
    {
        $upload = Upload::factory()->create();
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'validation_errors' => json_encode(['blacklist']),
        ]);
        Debtor::factory()->count(1)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'validation_errors' => json_encode(['other_error']),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.blacklisted', 2)
            ->assertJsonPath('data.invalid', 1);
    }

    public function test_validation_stats_counts_chargebacked_debtors(): void
    {
        $upload = Upload::factory()->create();
        $debtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
        $debtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $debtor1->billingAttempts()->create([
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'amount' => 100,
            'currency' => 'EUR',
            'transaction_id' => 'TXN1',
            'reference' => 'REF1',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.chargebacked', 1);
    }

    public function test_validation_stats_filters_by_legacy_model(): void
    {
        $upload = Upload::factory()->create();
        
        // Legacy debtors (no profile or explicit legacy profile)
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => null,
        ]);
        
        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $legacyProfile->id,
        ]);
        
        // Flywheel debtors
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats?debtor_type=legacy');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3);
    }

    public function test_validation_stats_filters_by_flywheel_model(): void
    {
        $upload = Upload::factory()->create();
        
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);
        
        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats?debtor_type=flywheel');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_validation_stats_filters_by_recovery_model(): void
    {
        $upload = Upload::factory()->create();
        
        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);
        
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats?debtor_type=recovery');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 3);
    }

    public function test_validation_stats_model_counts_correct(): void
    {
        $upload = Upload::factory()->create();
        
        // 2 Legacy (no profile)
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
        ]);
        
        // 2 Flywheel
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);
        
        // 1 Recovery
        $recoveryProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_RECOVERY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $recoveryProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.model_counts.all', 5)
            ->assertJsonPath('data.model_counts.legacy', 2)
            ->assertJsonPath('data.model_counts.flywheel', 2)
            ->assertJsonPath('data.model_counts.recovery', 1);
    }

    public function test_validation_stats_requires_authentication(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(401);
    }

    public function test_validation_stats_returns_404_for_nonexistent_upload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/99999/validation-stats');

        $response->assertStatus(404);
    }

    public function test_validation_stats_returns_zero_when_no_debtors(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.valid', 0)
            ->assertJsonPath('data.invalid', 0)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.blacklisted', 0)
            ->assertJsonPath('data.chargebacked', 0);
    }

    public function test_validation_stats_filters_all_returns_all_models(): void
    {
        $upload = Upload::factory()->create();
        
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
        ]);
        
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson('/api/admin/uploads/' . $upload->id . '/validation-stats?debtor_type=all');

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 4);
    }
}
