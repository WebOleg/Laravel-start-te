<?php

namespace Tests\Feature\Admin;

use App\Jobs\EmpRefreshByDateJob;
use App\Models\EmpAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmpRefreshControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected EmpAccount $empAccount;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->user = User::factory()->create();
        
        // Create a default EMP account for tests
        $this->empAccount = EmpAccount::create([
            'name' => 'Test Account',
            'slug' => 'test-account',
            'endpoint' => 'gate.emerchantpay.net',
            'username' => 'test_username',
            'password' => 'test_password',
            'terminal_token' => 'test_token_123',
            'is_active' => true,
        ]);
    }

    // AUTHENTICATION TESTS

    public function test_refresh_requires_authentication(): void
    {
        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(401);
    }

    public function test_current_status_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(401);
    }

    public function test_job_status_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/emp/refresh/test-job-id');

        $response->assertStatus(401);
    }

    // REFRESH ENDPOINT TESTS

    public function test_can_start_refresh_job(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'job_id',
                    'from',
                    'to',
                    'accounts_count',
                    'estimated_pages',
                    'queued',
                ],
            ])
            ->assertJsonPath('message', 'Refresh job started')
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.accounts_count', 1);

        Queue::assertPushed(EmpRefreshByDateJob::class, function ($job) {
            return $job->accountIds === [$this->empAccount->id];
        });
    }

    public function test_can_start_refresh_job_for_specific_account(): void
    {
        Sanctum::actingAs($this->user);

        // Create another account
        $account2 = EmpAccount::create([
            'name' => 'Test Account 2',
            'slug' => 'test-account-2',
            'endpoint' => 'gate.emerchantpay.net',
            'username' => 'test_username_2',
            'password' => 'test_password_2',
            'terminal_token' => 'test_token_456',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
            'emp_account_id' => $account2->id,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.accounts_count', 1);

        Queue::assertPushed(EmpRefreshByDateJob::class, function ($job) use ($account2) {
            return $job->accountIds === [$account2->id];
        });
    }

    public function test_refresh_validates_emp_account_exists(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
            'emp_account_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['emp_account_id']);
    }

    public function test_refresh_fails_when_no_accounts_configured(): void
    {
        Sanctum::actingAs($this->user);
        
        // Delete all accounts
        EmpAccount::query()->delete();

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No EMP accounts configured');
    }

    public function test_refresh_validates_from_date_is_required(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_refresh_validates_to_date_is_required(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_refresh_validates_from_date_format(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => 'invalid-date',
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from']);
    }

    public function test_refresh_validates_to_date_format(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => 'invalid-date',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_refresh_validates_to_date_is_after_or_equal_from_date(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-31',
            'to' => '2024-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_refresh_rejects_date_range_exceeding_90_days(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-04-02',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Date range cannot exceed 90 days');
    }

    public function test_refresh_accepts_exactly_90_days(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-03-31',
        ]);

        $response->assertStatus(202);
        Queue::assertPushed(EmpRefreshByDateJob::class);
    }

    public function test_refresh_prevents_duplicate_jobs(): void
    {
        Sanctum::actingAs($this->user);

        $response1 = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);
        $response1->assertStatus(202);
        $jobId = $response1->json('data.job_id');

        // Try to start second job while first is in progress
        $response2 = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-02-01',
            'to' => '2024-02-28',
        ]);

        $response2->assertStatus(409)
            ->assertJsonPath('message', 'Refresh already in progress')
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.duplicate', true);

        Queue::assertPushed(EmpRefreshByDateJob::class, 1);
    }

    public function test_refresh_clears_expired_active_job(): void
    {
        Sanctum::actingAs($this->user);

        $oldJobId = 'expired-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $oldJobId,
            'status' => 'processing',
            'started_at' => now()->subMinutes(10)->toIso8601String(),
        ], 3600);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-02-01',
            'to' => '2024-02-28',
        ]);

        $response->assertStatus(202);
        $newJobId = $response->json('data.job_id');
        $this->assertNotEquals($oldJobId, $newJobId);

        Queue::assertPushed(EmpRefreshByDateJob::class, 1);
    }

    public function test_refresh_creates_job_cache_entries(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/admin/emp/refresh', [
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ]);

        $response->assertStatus(202);
        $jobId = $response->json('data.job_id');

        // Check active job cache
        $activeJob = Cache::get('emp_refresh_active');
        $this->assertNotNull($activeJob);
        $this->assertEquals($jobId, $activeJob['job_id']);
        $this->assertEquals('processing', $activeJob['status']);
        $this->assertEquals('2024-01-01', $activeJob['from']);
        $this->assertEquals('2024-01-31', $activeJob['to']);
        $this->assertEquals([$this->empAccount->id], $activeJob['account_ids']);

        // Check job-specific cache
        $jobCache = Cache::get("emp_refresh_{$jobId}");
        $this->assertNotNull($jobCache);
        $this->assertEquals('pending', $jobCache['status']);
        $this->assertEquals(0, $jobCache['progress']);
        $this->assertEquals(1, $jobCache['accounts_total']);
        $this->assertEquals(0, $jobCache['accounts_processed']);
        $this->assertNull($jobCache['current_account']);
        $this->assertArrayHasKey('stats', $jobCache);
    }

    // CURRENT STATUS TESTS

    public function test_current_status_returns_not_processing_when_no_active_job(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false)
            ->assertJsonPath('data.job_id', null)
            ->assertJsonPath('data.progress', 0);
    }

    public function test_current_status_returns_processing_status(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
        ], 3600);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 50,
            'stats' => ['inserted' => 100, 'updated' => 50, 'unchanged' => 25, 'errors' => 0],
            'accounts_total' => 2,
            'accounts_processed' => 1,
            'current_account' => 'Test Account',
        ], 3600);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.accounts_total', 2)
            ->assertJsonPath('data.accounts_processed', 1)
            ->assertJsonPath('data.current_account', 'Test Account')
            ->assertJsonPath('data.stats.inserted', 100);
    }

    public function test_current_status_clears_cache_on_completion(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
        ], 3600);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'stats' => ['inserted' => 200],
        ], 3600);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false);

        $this->assertNull(Cache::get('emp_refresh_active'));
    }

    public function test_current_status_handles_pending_job(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'accounts_total' => 1,
        ], 3600);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true)
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.accounts_total', 1);
    }

    public function test_current_status_clears_stale_job(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->subMinutes(10)->toIso8601String(),
        ], 3600);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false);

        $this->assertNull(Cache::get('emp_refresh_active'));
    }

    // JOB STATUS TESTS

    public function test_can_get_job_status(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 75,
            'stats' => [
                'inserted' => 150,
                'updated' => 75,
                'unchanged' => 50,
                'errors' => 5,
            ],
            'accounts_total' => 2,
            'accounts_processed' => 1,
            'current_account' => 'Account 1',
            'started_at' => now()->toIso8601String(),
        ], 3600);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress', 75)
            ->assertJsonPath('data.stats.inserted', 150)
            ->assertJsonPath('data.accounts_total', 2)
            ->assertJsonPath('data.accounts_processed', 1)
            ->assertJsonPath('data.current_account', 'Account 1');
    }

    public function test_job_status_returns_pending_when_cache_not_found_but_active(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'accounts_total' => 1,
        ], 3600);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Job is pending')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress', 0);
    }

    public function test_job_status_returns_completed_when_not_found(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/emp/refresh/unknown-job-id');

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Job completed or expired')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100);
    }

    public function test_job_status_returns_completed_state(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'stats' => [
                'inserted' => 200,
                'updated' => 100,
                'unchanged' => 50,
                'errors' => 0,
            ],
            'accounts_total' => 2,
            'accounts_processed' => 2,
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ], 3600);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.accounts_processed', 2)
            ->assertJsonPath('data.stats.inserted', 200);
    }

    public function test_job_status_returns_completed_with_errors(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed_with_errors',
            'progress' => 100,
            'stats' => [
                'inserted' => 150,
                'updated' => 75,
                'unchanged' => 40,
                'errors' => 10,
            ],
            'completed_at' => now()->toIso8601String(),
        ], 3600);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed_with_errors')
            ->assertJsonPath('data.stats.errors', 10);
    }

    public function test_job_status_handles_missing_stats(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-123';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 25,
        ], 3600);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.stats.inserted', 0)
            ->assertJsonPath('data.stats.updated', 0)
            ->assertJsonPath('data.stats.unchanged', 0)
            ->assertJsonPath('data.stats.errors', 0);
    }
}
