<?php

namespace Tests\Feature;

use App\Jobs\ExportCleanUsersJob;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanUsersExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_clean_users_stats_returns_count(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'count',
                    'min_days',
                    'cutoff_date',
                    'streaming_threshold',
                ],
            ]);
    }

    public function test_clean_users_stats_accepts_min_days_parameter(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=60');

        $response->assertOk()
            ->assertJsonPath('data.min_days', 60);
    }

    public function test_clean_users_stats_validates_min_days(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=500');

        $response->assertUnprocessable();
    }

    public function test_export_requires_limit_parameter(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/export');

        $response->assertUnprocessable();
    }

    public function test_export_validates_limit_max(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=200000');

        $response->assertUnprocessable();
    }

    public function test_small_export_returns_streaming_response(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=100');

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_large_export_queues_job(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/export?limit=15000');

        $response->assertAccepted()
            ->assertJsonStructure([
                'data' => [
                    'job_id',
                    'status',
                    'message',
                ],
            ]);

        Queue::assertPushed(ExportCleanUsersJob::class);
    }

    public function test_export_status_returns_not_found_for_invalid_job(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/export/invalid-job-id/status');

        $response->assertNotFound();
    }

    public function test_export_status_returns_job_status(): void
    {
        $jobId = 'test-job-123';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'processing',
            'progress' => 50,
            'processed' => 5000,
            'limit' => 10000,
        ], now()->addHours(1));

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.progress', 50);
    }

    public function test_export_status_includes_download_url_when_completed(): void
    {
        $jobId = 'test-job-completed';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'processed' => 5000,
            'path' => 'exports/test/file.csv',
            'filename' => 'clean_users.csv',
        ], now()->addHours(1));

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonStructure(['data' => ['download_url']]);
    }

    public function test_download_returns_not_found_for_incomplete_job(): void
    {
        $jobId = 'test-job-456';
        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'processing',
            'progress' => 50,
        ], now()->addHours(1));

        $response = $this->actingAs($this->user)
            ->getJson("/api/admin/billing-attempts/clean-users/export/{$jobId}/download");

        $response->assertNotFound();
    }

    public function test_download_redirects_to_signed_url_for_completed_job(): void
    {
        Storage::fake('s3');

        $jobId = 'test-job-789';
        $path = "exports/{$jobId}/clean_users.csv";
        $filename = 'clean_users.csv';

        Storage::disk('s3')->put($path, "name,iban,amount,currency\nTest User,DE123,10.00,EUR");

        Cache::put("clean_users_export:{$jobId}", [
            'status' => 'completed',
            'progress' => 100,
            'path' => $path,
            'filename' => $filename,
        ], now()->addHours(1));

        $response = $this->actingAs($this->user)
            ->get("/api/admin/billing-attempts/clean-users/export/{$jobId}/download");

        // Should redirect to signed URL
        $response->assertOk()->assertDownload();
    }

    public function test_clean_users_query_excludes_chargebacked_debtors(): void
    {
        $upload = Upload::factory()->create();

        // Create debtor with approved billing
        $cleanDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $cleanDebtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);

        // Create debtor with chargeback
        $chargebackedDebtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        BillingAttempt::factory()->create([
            'debtor_id' => $chargebackedDebtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'attempt_number' => 1,
            'emp_created_at' => now()->subDays(60),
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $chargebackedDebtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'attempt_number' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/admin/billing-attempts/clean-users/stats?min_days=30');

        $response->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    public function test_unauthenticated_request_returns_unauthorized(): void
    {
        $response = $this->getJson('/api/admin/billing-attempts/clean-users/stats');

        $response->assertUnauthorized();
    }
}
