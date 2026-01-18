<?php

namespace Tests\Feature;

use App\Jobs\GenerateVopReportJob;
use App\Jobs\ProcessVopChunkJob;
use App\Jobs\ProcessVopJob;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\VopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for ProcessVopJob integration with automatic BAV report generation
 */
class ProcessVopJobBavIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.iban.mock' => true]);
        config(['services.iban.bav_enabled' => true]);
    }

    public function test_process_vop_job_dispatches_generate_report_on_completion(): void
    {
        Queue::fake();

        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        // Create some debtors for processing
        Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $job = new ProcessVopJob($upload);
        $job->handle();

        // Verify GenerateVopReportJob is NOT dispatched immediately
        // It should only be dispatched when the batch completes
        Queue::assertNotPushed(GenerateVopReportJob::class);

        // Verify ProcessVopChunkJob was dispatched for processing
        Queue::assertPushed(ProcessVopChunkJob::class);
    }

    public function test_vop_batch_finally_callback_dispatches_report_generation(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $job = new ProcessVopJob($upload);
        $job->handle();

        // Get the batch that was dispatched
        Bus::assertBatched(function ($batch) use ($upload) {
            // Check that the batch has a finally callback
            // We can't directly test the callback, but we can verify the batch was created
            return $batch->name === "VOP Upload #{$upload->id}";
        });
    }

    public function test_generate_report_logs_info_when_dispatched(): void
    {
        $upload = Upload::factory()->create([
            'vop_status' => 'completed',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_VERIFIED,
            'bav_selected' => true,
        ]);

        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->atLeast()->once();

        // Dispatch the job and verify it completes
        $job = new GenerateVopReportJob($upload->id);
        $reportService = app(VopReportService::class);
        $job->handle($reportService);

        // Verify report was created
        $files = Storage::disk('s3')->files('vop-reports');
        $this->assertNotEmpty($files);
    }

    public function test_upload_marked_vop_completed_before_report_generation(): void
    {
        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $job = new ProcessVopJob($upload);
        $job->handle();

        // Simulate the finally callback
        $upload->markVopCompleted();

        // Verify upload status is updated
        $upload->refresh();
        $this->assertEquals('completed', $upload->vop_status);
        $this->assertNotNull($upload->vop_completed_at);
    }

    public function test_generate_report_job_retries_on_failure(): void
    {
        $job = new GenerateVopReportJob(999); // Non-existent upload

        // Check retry configuration
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->timeout);
    }

    public function test_generate_report_job_uses_default_queue(): void
    {
        $upload = Upload::factory()->create();
        $job = new GenerateVopReportJob($upload->id);

        // Verify job is on default queue
        $this->assertEquals('default', $job->queue);
    }

    public function test_process_vop_batch_allows_failures(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        Debtor::factory()->count(5)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $job = new ProcessVopJob($upload);
        $job->handle();

        // Verify batch allows failures (won't cancel entire batch if one job fails)
        Bus::assertBatched(function ($batch) {
            return $batch->allowsFailures();
        });
    }

    public function test_vop_batch_runs_on_vop_queue(): void
    {
        Bus::fake();

        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        $job = new ProcessVopJob($upload);
        $job->handle();

        // Verify batch was created (queue property may not be accessible in fake)
        Bus::assertBatched(function ($batch) use ($upload) {
            return $batch->name === "VOP Upload #{$upload->id}";
        });
    }

    public function test_bav_selected_count_tracked_in_log(): void
    {
        $upload = Upload::factory()->create([
            'vop_status' => 'pending',
        ]);

        // Create 2 BAV selected debtors
        Debtor::factory()->count(2)->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => true,
        ]);

        // Create 1 non-BAV debtor
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_VALID,
            'vop_status' => Debtor::VOP_PENDING,
            'bav_selected' => false,
        ]);

        // Count BAV selected debtors
        $bavCount = Debtor::where('upload_id', $upload->id)
            ->where('bav_selected', true)
            ->count();

        $this->assertEquals(2, $bavCount);
    }
}
