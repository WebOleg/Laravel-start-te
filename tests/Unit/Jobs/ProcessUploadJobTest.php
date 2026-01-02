<?php

/**
 * Unit tests for ProcessUploadJob.
 * 
 * Stage A: Job accepts ALL rows without validation
 * Stage B: Validation runs separately
 */

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\Debtor;
use App\Services\SpreadsheetParserService;
use App\Services\IbanValidator;
use App\Services\BlacklistService;
use App\Services\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessUploadJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_processes_csv_file(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        
        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            new SpreadsheetParserService(),
            new IbanValidator(),
            app(BlacklistService::class),
            app(DeduplicationService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(1, $upload->processed_records);
        $this->assertEquals(0, $upload->failed_records);
        $this->assertNotNull($upload->processing_started_at);
        $this->assertNotNull($upload->processing_completed_at);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_job_accepts_all_rows_including_invalid(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,INVALID,100.00\nJane,Smith,DE89370400440532013000,200.00";
        
        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 2,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            new SpreadsheetParserService(),
            new IbanValidator(),
            app(BlacklistService::class),
            app(DeduplicationService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(2, $upload->processed_records);
        $this->assertEquals(0, $upload->failed_records);

        $this->assertDatabaseCount('debtors', 2);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'iban' => 'INVALID',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
    }

    public function test_job_completes_even_with_all_invalid_data(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,INVALID,100.00";
        
        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            new SpreadsheetParserService(),
            new IbanValidator(),
            app(BlacklistService::class),
            app(DeduplicationService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(1, $upload->processed_records);
        $this->assertEquals(0, $upload->failed_records);
    }

    public function test_failed_method_updates_upload_status(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
        ]);

        $job = new ProcessUploadJob($upload, []);
        $job->failed(new \Exception('Test error'));

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_FAILED, $upload->status);
        $this->assertNotNull($upload->processing_completed_at);
        $this->assertEquals('Test error', $upload->meta['error']);
    }
}
