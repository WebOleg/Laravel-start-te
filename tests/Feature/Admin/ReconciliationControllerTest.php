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
        Cache::put("reconciliation_upload_{$this->upload->id}", true, now()->addMinutes(30));

        $response = $this->actingAs($this->user)
            ->postJson("/api/admin/uploads/{$this->upload->id}/reconcile");

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
            'created_at' => now()->subHours(3), // 3 hours ago - within 24h max_age
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
