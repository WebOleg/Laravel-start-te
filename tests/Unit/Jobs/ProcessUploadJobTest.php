<?php

/**
 * Unit tests for ProcessUploadJob.
 *
 * Stage A: Job accepts ALL rows without validation
 * Stage B: Validation runs separately
 */

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessUploadChunkJob;
use App\Jobs\ProcessUploadJob;
use App\Models\Upload;
use App\Models\Debtor;
use App\Services\DebtorImportService;
use App\Services\FileUploadService;
use App\Services\SpreadsheetParserService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
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

    public function test_unique_id_includes_upload_id(): void
    {
        $upload = Upload::factory()->create();

        $job = new ProcessUploadJob($upload, []);

        $this->assertEquals('upload_' . $upload->id, $job->uniqueId());
    }

    public function test_job_has_correct_retry_configuration(): void
    {
        $upload = Upload::factory()->create();

        $job = new ProcessUploadJob($upload, []);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(600, $job->timeout);
        $this->assertEquals([30, 60, 120], $job->backoff);
    }

    public function test_job_is_queued_on_default_queue(): void
    {
        $upload = Upload::factory()->create();

        $job = new ProcessUploadJob($upload, []);

        $this->assertEquals('default', $job->queue);
    }

    public function test_job_transitions_status_to_processing_on_start(): void
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        // After completion it should be COMPLETED, but processing_started_at
        // proves it went through PROCESSING first
        $upload->refresh();
        $this->assertNotNull($upload->processing_started_at);
    }

    public function test_job_sets_processing_completed_at_on_success(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJane,Smith,DE89370400440532013000,50.00";

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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();
        $this->assertNotNull($upload->processing_completed_at);
        $this->assertTrue($upload->processing_completed_at->gte($upload->processing_started_at));
    }

    public function test_job_throws_when_s3_file_missing(): void
    {
        $upload = Upload::factory()->create([
            'file_path' => 'uploads/nonexistent_file.csv',
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        $job = new ProcessUploadJob($upload, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found in S3');

        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );
    }

    public function test_job_handles_unsupported_file_extension(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.json';
        Storage::disk('s3')->put($filePath, '{"not":"a spreadsheet"}');

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        $job = new ProcessUploadJob($upload, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type: json');

        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );
    }

    public function test_temp_file_is_cleaned_up_after_success(): void
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        // Ensure no leftover temp files for this upload
        $tempFiles = glob(sys_get_temp_dir() . '/upload_' . $upload->id . '_*');
        $this->assertEmpty($tempFiles, 'Temp files should be cleaned up after processing');
    }

    public function test_temp_file_is_cleaned_up_after_failure(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        // Completely empty file — will likely fail during parse
        Storage::disk('s3')->put($filePath, '');

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 0,
        ]);

        $job = new ProcessUploadJob($upload, []);

        try {
            $job->handle(
                app(SpreadsheetParserService::class),
                app(DebtorImportService::class)
            );
        } catch (\Throwable) {
            // Expected — we just want to verify cleanup
        }

        $tempFiles = glob(sys_get_temp_dir() . '/upload_' . $upload->id . '_*');
        $this->assertEmpty($tempFiles, 'Temp files should be cleaned up even after failure');
    }

    public function test_job_updates_total_records_from_parsed_rows(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\n"
            . "John,Doe,DE89370400440532013000,100.00\n"
            . "Jane,Smith,DE89370400440532013000,200.00\n"
            . "Bob,Jones,DE89370400440532013000,300.00";

        Storage::disk('s3')->put($filePath, $content);

        // Intentionally set wrong total_records — job should overwrite it
        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 99,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();
        $this->assertEquals(3, $upload->total_records);
    }

    public function test_job_dispatches_batch_for_large_row_count(): void
    {
        Bus::fake();

        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $header = "first_name,last_name,iban,amount\n";
        $rows = '';
        for ($i = 0; $i < 150; $i++) {
            $rows .= "User{$i},Last{$i},DE89370400440532013000," . ($i * 10) . ".00\n";
        }

        Storage::disk('s3')->put($filePath, $header . $rows);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 150,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        Bus::assertBatched(function (PendingBatch $batch) use ($upload) {
            return $batch->name === "Upload #{$upload->id}"
                && $batch->allowsFailures();
        });
    }

    public function test_job_processes_multiple_valid_rows(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\n"
            . "Alice,Anderson,DE89370400440532013000,100.00\n"
            . "Bob,Brown,DE89370400440532013000,200.00\n"
            . "Charlie,Clark,DE89370400440532013000,300.00";

        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 3,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(3, $upload->processed_records);
        $this->assertEquals(0, $upload->failed_records);
        $this->assertDatabaseCount('debtors', 3);

        foreach (['Alice', 'Bob', 'Charlie'] as $name) {
            $this->assertDatabaseHas('debtors', [
                'upload_id' => $upload->id,
                'first_name' => $name,
            ]);
        }
    }

    public function test_job_works_with_remapped_columns(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        // CSV uses different header names than the DB columns
        $content = "fname,lname,bank_iban,debt_amount\nJohn,Doe,DE89370400440532013000,100.00";

        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
        ]);

        // Mapping: csv_header => db_column
        $columnMapping = [
            'fname' => 'first_name',
            'lname' => 'last_name',
            'bank_iban' => 'iban',
            'debt_amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();
        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_failed_method_preserves_existing_meta(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'meta' => ['original_filename' => 'data.csv', 'custom_key' => 'value'],
        ]);

        $job = new ProcessUploadJob($upload, []);
        $job->failed(new \Exception('Something broke'));

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_FAILED, $upload->status);
        $this->assertEquals('Something broke', $upload->meta['error']);
        // Original meta keys should still be present
        $this->assertEquals('data.csv', $upload->meta['original_filename']);
        $this->assertEquals('value', $upload->meta['custom_key']);
    }

    public function test_failed_method_handles_null_meta(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'meta' => null,
        ]);

        $job = new ProcessUploadJob($upload, []);
        $job->failed(new \Exception('Null meta error'));

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_FAILED, $upload->status);
        $this->assertEquals('Null meta error', $upload->meta['error']);
    }

    public function test_job_applies_global_lock_when_meta_flag_set(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";

        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 1,
            'meta' => ['apply_global_lock' => true],
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        // skipped_locked should exist in meta
        $this->assertArrayHasKey('skipped_locked', $upload->meta);
    }

    public function test_job_handles_csv_with_only_headers(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        $content = "first_name,last_name,iban,amount\n";

        Storage::disk('s3')->put($filePath, $content);

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
            'total_records' => 0,
        ]);

        $columnMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'iban' => 'iban',
            'amount' => 'amount',
        ];

        $job = new ProcessUploadJob($upload, $columnMapping);
        $job->handle(
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(0, $upload->total_records);
        $this->assertDatabaseCount('debtors', 0);
    }


    public function test_job_rethrows_exception_after_logging(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.csv';
        Storage::disk('s3')->put($filePath, 'not,a,valid,csv,with,proper,data');

        $upload = Upload::factory()->create([
            'file_path' => $filePath,
            'status' => Upload::STATUS_PENDING,
        ]);

        $importer = $this->createMock(DebtorImportService::class);
        $importer->method('importRows')
            ->willThrowException(new \RuntimeException('Import exploded'));

        $job = new ProcessUploadJob($upload, [
            'not' => 'first_name',
            'a' => 'last_name',
            'valid' => 'iban',
            'csv' => 'amount',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Import exploded');

        $job->handle(
            app(SpreadsheetParserService::class),
            $importer
        );
    }

    public function test_job_processes_txt_file_as_csv(): void
    {
        $filePath = 'uploads/test_' . uniqid() . '.txt';
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
            app(SpreadsheetParserService::class),
            app(DebtorImportService::class)
        );

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'John',
        ]);
    }
}
