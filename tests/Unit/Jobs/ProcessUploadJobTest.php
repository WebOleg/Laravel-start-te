<?php

/**
 * Unit tests for ProcessUploadJob.
 */

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\Debtor;
use App\Services\SpreadsheetParserService;
use App\Services\IbanValidator;
use App\Services\BlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessUploadJobTest extends TestCase
{
    use RefreshDatabase;

    private string $testFilePath;

    protected function tearDown(): void
    {
        if (isset($this->testFilePath) && file_exists(storage_path('app/' . $this->testFilePath))) {
            unlink(storage_path('app/' . $this->testFilePath));
        }
        parent::tearDown();
    }

    public function test_job_processes_csv_file(): void
    {
        $this->testFilePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        
        $fullPath = storage_path('app/' . $this->testFilePath);
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $this->testFilePath,
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
            new BlacklistService(new IbanValidator())
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

    public function test_job_handles_invalid_rows(): void
    {
        $this->testFilePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,INVALID,100.00\nJane,Smith,DE89370400440532013000,200.00";
        
        $fullPath = storage_path('app/' . $this->testFilePath);
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $this->testFilePath,
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
            new BlacklistService(new IbanValidator())
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(1, $upload->processed_records);
        $this->assertEquals(1, $upload->failed_records);
    }

    public function test_job_updates_status_to_failed_when_all_rows_fail(): void
    {
        $this->testFilePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,INVALID,100.00";
        
        $fullPath = storage_path('app/' . $this->testFilePath);
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        file_put_contents($fullPath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $this->testFilePath,
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
            new BlacklistService(new IbanValidator())
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_FAILED, $upload->status);
        $this->assertEquals(0, $upload->processed_records);
        $this->assertEquals(1, $upload->failed_records);
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
