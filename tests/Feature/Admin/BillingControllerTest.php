<?php

/**
 * Feature tests for Admin Billing API endpoints.
 */

namespace Tests\Feature\Admin;

use App\Jobs\ProcessBillingJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
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
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PENDING,
        ]);

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
        
        // Valid debtor
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PENDING,
        ]);
        
        // Invalid debtor - should be skipped
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_INVALID,
            'status' => Debtor::STATUS_PENDING,
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
            'status' => Debtor::STATUS_PENDING,
        ]);

        // Create pending billing attempt
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

    public function test_sync_skips_debtors_with_approved_billing(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PENDING,
        ]);

        // Create approved billing attempt
        BillingAttempt::factory()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0);
    }

    public function test_sync_prevents_duplicate_dispatch(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create();
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'status' => Debtor::STATUS_PENDING,
        ]);

        // Set lock
        Cache::put("billing_sync_{$upload->id}", true, 300);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->postJson("/api/admin/uploads/{$upload->id}/sync");

        $response->assertStatus(409)
            ->assertJsonPath('data.duplicate', true);

        Bus::assertNotDispatched(ProcessBillingJob::class);
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

    public function test_stats_returns_zero_for_empty_upload(): void
    {
        $upload = Upload::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->token)
            ->getJson("/api/admin/uploads/{$upload->id}/billing-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.total_attempts', 0)
            ->assertJsonPath('data.approved', 0);
    }
}
