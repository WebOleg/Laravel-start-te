<?php

namespace Tests\Feature\Admin;

use App\Jobs\EmpRefreshByDateJob;
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

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();
        $this->user = User::factory()->create();
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
                    'estimated_pages',
                    'queued',
                ],
            ])
            ->assertJsonPath('message', 'Refresh job started')
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(EmpRefreshByDateJob::class);
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
            'from' => '01-01-2024',
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
            'to' => '31-01-2024',
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
            'to' => '2024-04-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Date range cannot exceed 90 days');

        Queue::assertNotPushed(EmpRefreshByDateJob::class);
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

        // Start first job
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
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.job_id', $jobId);

        Queue::assertPushed(EmpRefreshByDateJob::class, 1);
    }

    public function test_refresh_clears_expired_active_job(): void
    {
        Sanctum::actingAs($this->user);

        // Simulate an expired job from 10 minutes ago
        $oldJobId = 'old-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $oldJobId,
            'status' => 'processing',
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        // Start new job
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

        // Check job status cache
        $jobStatus = Cache::get("emp_refresh_{$jobId}");
        $this->assertNotNull($jobStatus);
        $this->assertEquals('pending', $jobStatus['status']);
        $this->assertEquals(0, $jobStatus['progress']);
        $this->assertIsArray($jobStatus['stats']);
    }

    // CURRENT STATUS ENDPOINT TESTS

    public function test_current_status_returns_not_processing_when_no_active_job(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'is_processing',
                    'job_id',
                    'progress',
                    'stats',
                ],
            ])
            ->assertJsonPath('data.is_processing', false)
            ->assertJsonPath('data.job_id', null)
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.stats', null);
    }

    public function test_current_status_returns_processing_status(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        $stats = [
            'inserted' => 10,
            'updated' => 5,
            'unchanged' => 3,
            'errors' => 0,
        ];

        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 45,
            'stats' => $stats,
            'started_at' => now()->toIso8601String(),
        ], 7200);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.progress', 45)
            ->assertJsonPath('data.stats.inserted', 10)
            ->assertJsonPath('data.stats.updated', 5);
    }

    public function test_current_status_clears_cache_on_completion(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'stats' => [
                'inserted' => 100,
                'updated' => 50,
                'unchanged' => 20,
                'errors' => 0,
            ],
            'started_at' => now()->subMinutes(5)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ], 7200);

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false)
            ->assertJsonPath('data.progress', 100);

        $this->assertNull(Cache::get('emp_refresh_active'));
    }

    public function test_current_status_handles_pending_job(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->subMinutes(1)->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        // No job status cache entry yet (job is pending)

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', true)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.progress', 0);
    }

    public function test_current_status_clears_stale_job(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        // No job status cache entry and job is older than 5 minutes

        $response = $this->getJson('/api/admin/emp/refresh/status');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_processing', false)
            ->assertJsonPath('data.progress', 100);

        $this->assertNull(Cache::get('emp_refresh_active'));
    }

    // JOB STATUS ENDPOINT TESTS

    public function test_can_get_job_status(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        $stats = [
            'inserted' => 25,
            'updated' => 10,
            'unchanged' => 5,
            'errors' => 1,
        ];

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 60,
            'stats' => $stats,
            'started_at' => now()->subMinutes(2)->toIso8601String(),
            'completed_at' => null,
        ], 7200);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'job_id',
                    'status',
                    'progress',
                    'stats',
                    'started_at',
                    'completed_at',
                ],
            ])
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress', 60)
            ->assertJsonPath('data.stats.inserted', 25);
    }

    public function test_job_status_returns_pending_when_cache_not_found_but_active(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => '2024-01-01',
            'to' => '2024-01-31',
        ], 7200);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.progress', 0);
    }

    public function test_job_status_returns_completed_when_not_found(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'nonexistent-job-id';

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.job_id', $jobId)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100);
    }

    public function test_job_status_returns_completed_state(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'stats' => [
                'inserted' => 100,
                'updated' => 50,
                'unchanged' => 30,
                'errors' => 0,
            ],
            'started_at' => now()->subMinutes(5)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ], 7200);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 100);
    }

    public function test_job_status_returns_completed_with_errors(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'completed_with_errors',
            'progress' => 100,
            'stats' => [
                'inserted' => 80,
                'updated' => 40,
                'unchanged' => 10,
                'errors' => 5,
            ],
            'started_at' => now()->subMinutes(10)->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
        ], 7200);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'completed_with_errors')
            ->assertJsonPath('data.stats.errors', 5);
    }

    public function test_job_status_handles_missing_stats(): void
    {
        Sanctum::actingAs($this->user);

        $jobId = 'test-job-id';
        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'processing',
            'progress' => 30,
            'started_at' => now()->toIso8601String(),
        ], 7200);

        $response = $this->getJson("/api/admin/emp/refresh/{$jobId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.stats.inserted', 0)
            ->assertJsonPath('data.stats.updated', 0)
            ->assertJsonPath('data.stats.unchanged', 0)
            ->assertJsonPath('data.stats.errors', 0);
    }
}
