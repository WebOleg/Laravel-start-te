<?php

namespace Tests\Feature\Admin;

use App\Jobs\ProcessReconciliationJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconciliationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Upload $upload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->upload = Upload::factory()->create();
    }

    public function test_reconcile_single_attempt_success(): void
    {
        Http::fake([
            '*' => Http::response('<?xml version="1.0"?><payment_response><status>approved</status><unique_id>emp_123</unique_id></payment_response>', 200),
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123',
            'created_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(200)
            ->assertJsonPath('data.success', true);
    }

    public function test_reconcile_attempt_without_unique_id_fails(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(422)
            ->assertJsonPath('data.can_reconcile', false);
    }

    public function test_reconcile_non_pending_attempt_fails(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'approved',
            'unique_id' => 'emp_123',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(422)
            ->assertJsonPath('data.can_reconcile', false);
    }

    public function test_reconcile_attempt_max_attempts_reached_fails(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123',
            'reconciliation_attempts' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(422)
            ->assertJsonPath('data.can_reconcile', false);
    }

    public function test_reconcile_upload_dispatches_job(): void
    {
        Bus::fake();

        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'created_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/reconcile");

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(ProcessReconciliationJob::class);
    }

    public function test_reconcile_upload_no_eligible_attempts(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/reconcile");

        $response->assertStatus(200)
            ->assertJsonPath('data.eligible', 0)
            ->assertJsonPath('data.queued', false);
    }

    public function test_reconcile_upload_prevents_duplicate(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'reconciliation_status' => Upload::JOB_PROCESSING,
            'reconciliation_batch_id' => 'test-batch-id',
            'reconciliation_started_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$upload->id}/reconcile");

        $response->assertStatus(409)
            ->assertJsonPath('data.duplicate', true);

        Bus::assertNotDispatched(ProcessReconciliationJob::class);
    }

    public function test_get_reconciliation_stats(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/reconciliation/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'pending_total',
                    'pending_stale',
                    'never_reconciled',
                    'maxed_out_attempts',
                    'eligible',
                ],
            ]);
    }

    public function test_get_reconciliation_stats_with_data(): void
    {
        // Create pending attempts across multiple uploads
        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();

        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);

        // Pending attempts (recent - less than 24 hours)
        BillingAttempt::factory()->count(3)->sequence(
            ['unique_id' => 'emp_recent_1'],
            ['unique_id' => 'emp_recent_2'],
            ['unique_id' => 'emp_recent_3'],
        )->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'created_at' => now()->subHours(6),
            'reconciliation_attempts' => 0,
        ]);

        // Pending attempts (stale - more than 24 hours)
        BillingAttempt::factory()->count(2)->sequence(
            ['unique_id' => 'emp_stale_1'],
            ['unique_id' => 'emp_stale_2'],
        )->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'created_at' => now()->subHours(48),
            'reconciliation_attempts' => 0,
        ]);

        // Never reconciled attempts (old, but never attempted)
        BillingAttempt::factory()->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'unique_id' => 'emp_never_reconciled',
            'created_at' => now()->subDays(5),
            'reconciliation_attempts' => 0,
        ]);

        // Maxed out attempts
        BillingAttempt::factory()->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor2->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'unique_id' => 'emp_maxed_out',
            'created_at' => now()->subHours(72),
            'reconciliation_attempts' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/reconciliation/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'pending_total',
                    'pending_stale',
                    'never_reconciled',
                    'maxed_out_attempts',
                    'eligible',
                ],
            ])
            ->assertJsonPath('data.pending_total', 7)
            ->assertJsonPath('data.pending_stale', 2);
    }

    public function test_get_upload_reconciliation_stats_with_data(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);

        // Create pending attempts
        BillingAttempt::factory()->count(3)->sequence(
            ['unique_id' => 'pending_1'],
            ['unique_id' => 'pending_2'],
            ['unique_id' => 'pending_3'],
        )->create([
            'upload_id' => $this->upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'created_at' => now()->subHours(6),
            'reconciliation_attempts' => 0,
        ]);

        // Create reconciled attempts (from today)
        BillingAttempt::factory()->count(2)->sequence(
            ['unique_id' => 'reconciled_1'],
            ['unique_id' => 'reconciled_2'],
        )->create([
            'upload_id' => $this->upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(5),
            'reconciliation_attempts' => 1,
            'last_reconciled_at' => now(),
        ]);

        // Create other attempts
        BillingAttempt::factory()->create([
            'upload_id' => $this->upload->id,
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'unique_id' => 'other_attempt',
            'created_at' => now()->subDays(10),
            'reconciliation_attempts' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.upload_id', $this->upload->id)
            ->assertJsonPath('data.total', 6)
            ->assertJsonPath('data.pending', 3)
            ->assertJsonPath('data.reconciled_today', 2);
    }

    public function test_get_upload_reconciliation_stats_with_no_attempts(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.upload_id', $this->upload->id)
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.eligible', 0)
            ->assertJsonPath('data.reconciled_today', 0);
    }

    public function test_get_upload_reconciliation_stats_requires_authentication(): void
    {
        $response = $this->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(401);
    }

    public function test_get_upload_reconciliation_stats_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/uploads/99999/reconciliation-stats');

        $response->assertStatus(404);
    }

    public function test_get_upload_reconciliation_stats_detects_processing(): void
    {
        $this->upload->update([
            'reconciliation_status' => Upload::JOB_PROCESSING,
            'reconciliation_batch_id' => 'batch-123',
            'reconciliation_started_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true)
            ->assertJsonPath('data.reconciliation_status', Upload::JOB_PROCESSING);
    }

    public function test_get_global_reconciliation_stats_excludes_maxed_out(): void
    {
        $debtor1 = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $this->upload->id]);

        // Eligible attempt
        BillingAttempt::factory()->create([
            'upload_id' => $this->upload->id,
            'debtor_id' => $debtor1->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'unique_id' => 'eligible_attempt',
            'created_at' => now()->subHours(6),
            'reconciliation_attempts' => 5,
        ]);

        // Maxed out attempt (should not be in eligible count)
        BillingAttempt::factory()->create([
            'upload_id' => $this->upload->id,
            'debtor_id' => $debtor2->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'unique_id' => 'maxed_out_attempt',
            'created_at' => now()->subHours(6),
            'reconciliation_attempts' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/reconciliation/stats');

        $response->assertStatus(200)
            ->assertJsonPath('data.pending_total', 2)
            ->assertJsonPath('data.maxed_out_attempts', 1)
            ->assertJsonPath('data.eligible', 1);
    }

    public function test_get_upload_reconciliation_stats_includes_timestamps(): void
    {
        $startTime = now();
        $endTime = now()->addHours(1);

        $this->upload->update([
            'reconciliation_status' => Upload::JOB_COMPLETED,
            'reconciliation_started_at' => $startTime,
            'reconciliation_completed_at' => $endTime,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'upload_id',
                    'is_processing',
                    'reconciliation_status',
                    'total',
                    'pending',
                    'eligible',
                    'reconciled_today',
                ],
            ]);
    }

    public function test_bulk_reconciliation_dispatches_job(): void
    {
        Bus::fake();

        // Create an active EMP account
        $empAccount = \App\Models\EmpAccount::create([
            'name' => 'Test EMP Account',
            'slug' => 'test-emp',
            'endpoint' => 'https://test.emp.com',
            'username' => 'test_user',
            'password' => 'test_pass',
            'terminal_token' => 'test_token',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'emp_account_id' => $empAccount->id,  // Add this
            'created_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/reconciliation/bulk', [
                'max_age_hours' => 24,
                'limit' => 100,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.emp_accounts', fn($accounts) => count($accounts) > 0);

        Bus::assertDispatched(ProcessReconciliationJob::class);
    }
}
