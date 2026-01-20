<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessVopJob;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Models\User;
use App\Models\VopLog;
use App\Services\IbanBavService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class VopControllerTest extends TestCase
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

    public function test_stats_requires_authentication(): void
    {
        $upload = Upload::factory()->create();
        $response = $this->getJson("/api/admin/uploads/{$upload->id}/vop-stats");
        $response->assertStatus(401);
    }

    public function test_stats_returns_correct_counts(): void
    {
        $upload = Upload::factory()->create();

        // 2 Valid Debtors
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // 1 Invalid Debtor (Should be ignored by total_eligible)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/vop-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_eligible', 2)
            ->assertJsonPath('data.verified', 0);
    }

    public function test_stats_filters_by_billing_model_flywheel(): void
    {
        $upload = Upload::factory()->create();

        // 1. Flywheel Debtor (Target)
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // 2. Legacy Debtor (Should be ignored)
        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $legacyProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/vop-stats?debtor_type=" . DebtorProfile::MODEL_FLYWHEEL);

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.total_eligible'), 'Should only count Flywheel debtors');
    }

    public function test_stats_filters_by_billing_model_legacy_includes_null_profiles(): void
    {
        $upload = Upload::factory()->create();

        // 1. Explicit Legacy
        $legacyProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_LEGACY]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $legacyProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // 2. Implicit Legacy (No Profile)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => null,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // 3. Flywheel (Should be ignored)
        $flywheelProfile = DebtorProfile::factory()->create(['billing_model' => DebtorProfile::MODEL_FLYWHEEL]);
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'debtor_profile_id' => $flywheelProfile->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/vop-stats?debtor_type=" . DebtorProfile::MODEL_LEGACY);

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.total_eligible'), 'Should count Explicit Legacy + No Profile');
    }

    public function test_verify_dispatches_job(): void
    {
        Bus::fake();
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/verify-vop");

        $response->assertStatus(202);

        Bus::assertDispatched(ProcessVopJob::class, function ($job) use ($upload) {
            return $job->upload->id === $upload->id && $job->forceRefresh === false;
        });
    }

    public function test_verify_blocks_duplicate_requests(): void
    {
        Bus::fake();

        // FIX: Use string literal 'processing' instead of undefined constant
        $upload = Upload::factory()->create([
            'vop_status' => 'processing'
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/verify-vop");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'VOP verification already in progress');

        Bus::assertNotDispatched(ProcessVopJob::class);
    }

    public function test_verify_allows_force_refresh_on_duplicate(): void
    {
        Bus::fake();

        // FIX: Use string literal 'processing'
        $upload = Upload::factory()->create([
            'vop_status' => 'processing'
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/verify-vop", [
                'force' => true
            ]);

        $response->assertStatus(202);

        Bus::assertDispatched(ProcessVopJob::class, function ($job) {
            return $job->forceRefresh === true;
        });
    }

    public function test_verify_single_calls_service(): void
    {
        // Mock the IbanBavService that is resolved via app()
        $mockService = Mockery::mock(IbanBavService::class);
        $mockService->shouldReceive('verify')
            ->once()
            ->with('DE123456789', 'John Doe')
            ->andReturn(['valid' => true, 'score' => 1.0]);

        $this->app->instance(IbanBavService::class, $mockService);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/vop/verify-single', [
                'iban' => 'DE123456789',
                'name' => 'John Doe',
                'use_mock' => false
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.valid', true);
    }

    public function test_verify_single_validates_input(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson('/api/admin/vop/verify-single', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['iban', 'name']);
    }

    public function test_logs_returns_paginated_logs_for_upload(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        VopLog::factory()->count(15)->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id
        ]);

        // Create logs for another upload (should not be returned)
        $otherUpload = Upload::factory()->create();
        VopLog::factory()->create(['upload_id' => $otherUpload->id]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/vop-logs");

        $response->assertStatus(200);
        $this->assertCount(15, $response->json('data'));
        $this->assertEquals($upload->id, $response->json('data.0.upload_id'));
    }
}
