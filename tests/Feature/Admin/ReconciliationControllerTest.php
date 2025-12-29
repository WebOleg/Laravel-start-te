<?php

namespace Tests\Feature\Admin;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use App\Jobs\ProcessReconciliationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
        $this->upload = Upload::factory()->create(['status' => 'completed']);
    }

    public function test_reconcile_single_attempt_success(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'created_at' => now()->subHours(3),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'success', 'changed', 'previous_status', 'new_status'],
            ]);
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
            'unique_id' => 'emp_123456',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(422);
    }

    public function test_reconcile_attempt_max_attempts_reached_fails(): void
    {
        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'reconciliation_attempts' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/billing-attempts/{$attempt->id}/reconcile");

        $response->assertStatus(422);
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
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.eligible', 1);

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

        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'created_at' => now()->subHours(3),
        ]);

        Cache::put("reconciliation_upload_{$this->upload->id}", true, 30);

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/reconcile");

        $response->assertStatus(409)
            ->assertJsonPath('data.duplicate', true);
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

    public function test_get_upload_reconciliation_stats(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/uploads/{$this->upload->id}/reconciliation-stats");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'upload_id',
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

        $debtor = Debtor::factory()->create(['upload_id' => $this->upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $this->upload->id,
            'status' => 'pending',
            'unique_id' => 'emp_123456',
            'created_at' => now()->subHours(25),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/admin/reconciliation/bulk', [
                'max_age_hours' => 24,
                'limit' => 100,
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        Bus::assertDispatched(ProcessReconciliationJob::class);
    }
}
