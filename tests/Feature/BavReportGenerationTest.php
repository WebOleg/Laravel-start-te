<?php

namespace Tests\Feature;

use App\Jobs\GenerateVopReportJob;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use App\Services\VopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests for BAV CSV report generation and logging functionality
 */
class BavReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    private VopReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->reportService = app(VopReportService::class);
    }

    public function test_bav_report_includes_only_bav_selected_debtors(): void
    {
        $upload = Upload::factory()->create();

        // Create BAV selected debtors
        $bavDebtor1 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $bavDebtor2 = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'iban' => 'DE89370400440532013001',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // Create non-BAV debtors (should be excluded)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Bob',
            'last_name' => 'Wilson',
            'iban' => 'DE89370400440532013002',
            'bav_selected' => false,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // Create VOP logs for BAV debtors
        VopLog::factory()->create([
            'debtor_id' => $bavDebtor1->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_YES,
            'name_match_score' => 100,
            'bav_verified' => true,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $bavDebtor2->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_PARTIAL,
            'name_match_score' => 70,
            'bav_verified' => true,
        ]);

        // Generate report
        $reportPath = $this->reportService->generateReport($upload->id);

        // Assert file was created
        Storage::disk('s3')->assertExists($reportPath);

        // Get CSV content
        $csvContent = Storage::disk('s3')->get($reportPath);
        $lines = explode("\n", trim($csvContent));

        // Should have header + 2 BAV debtors only
        $this->assertCount(3, $lines, 'CSV should contain header + 2 BAV debtors');

        // Check header
        $this->assertStringContainsString('first_name', $lines[0]);
        $this->assertStringContainsString('last_name', $lines[0]);
        $this->assertStringContainsString('iban', $lines[0]);
        $this->assertStringContainsString('bav_result', $lines[0]);
        $this->assertStringContainsString('bav_score', $lines[0]);
        $this->assertStringContainsString('bav_message', $lines[0]);

        // Check that John and Jane are in the report
        $this->assertStringContainsString('John', $csvContent);
        $this->assertStringContainsString('Jane', $csvContent);

        // Check that Bob (non-BAV) is NOT in the report
        $this->assertStringNotContainsString('Bob', $csvContent);
        $this->assertStringNotContainsString('Wilson', $csvContent);
    }

    public function test_bav_report_csv_has_correct_columns(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'iban' => 'DE89370400440532013000',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_YES,
            'name_match_score' => 100,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);

        $csvContent = Storage::disk('s3')->get($reportPath);
        $lines = array_map('str_getcsv', explode("\n", trim($csvContent)));

        // Check header columns
        $header = $lines[0];
        $this->assertEquals(['first_name', 'last_name', 'iban', 'bav_result', 'bav_score', 'bav_message'], $header);

        // Check data row has same number of columns
        $dataRow = $lines[1];
        $this->assertCount(6, $dataRow);
        $this->assertEquals('Test', $dataRow[0]);
        $this->assertEquals('User', $dataRow[1]);
        $this->assertEquals('DE89370400440532013000', $dataRow[2]);
        $this->assertEquals('yes', $dataRow[3]);
        $this->assertEquals('100', $dataRow[4]);
        $this->assertNotEmpty($dataRow[5]); // bav_message should exist
    }

    public function test_bav_report_filename_format(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);

        // Check filename format: vop-reports/bav_report_UPLOADID_TIMESTAMP.csv
        $this->assertStringStartsWith('vop-reports/bav_report_', $reportPath);
        $this->assertStringContainsString("_{$upload->id}_", $reportPath);
        $this->assertStringEndsWith('.csv', $reportPath);
    }

    public function test_bav_report_s3_upload_success_logs_to_bav_channel(): void
    {
        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'BAV CSV saved to S3 successfully' &&
                       isset($context['upload_id']) &&
                       isset($context['path']) &&
                       isset($context['size']);
            })
            ->once();

        Log::shouldReceive('info')
            ->withArgs(function ($message, $context) {
                return $message === 'BAV CSV report generated';
            })
            ->once();

        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'bav_verified' => true,
        ]);

        $this->reportService->generateReport($upload->id);
    }

    public function test_bav_report_s3_upload_failure_throws_exception(): void
    {
        // Force S3 to fail by using a disk that doesn't allow writes
        Storage::shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        Storage::shouldReceive('put')
            ->andReturn(false);

        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('error')
            ->withArgs(function ($message) {
                return $message === 'Failed to save BAV CSV to S3';
            })
            ->once();

        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'bav_verified' => true,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to save BAV CSV report to S3');

        $this->reportService->generateReport($upload->id);
    }

    public function test_bav_report_s3_exception_logs_error_with_trace(): void
    {
        Storage::shouldReceive('disk')
            ->with('s3')
            ->andReturnSelf();

        Storage::shouldReceive('put')
            ->andThrow(new \Exception('S3 connection timeout'));

        Log::shouldReceive('channel')
            ->with('bav')
            ->andReturnSelf();

        Log::shouldReceive('error')
            ->withArgs(function ($message, $context) {
                return $message === 'Exception saving BAV CSV to S3' &&
                       isset($context['error']) &&
                       isset($context['trace']) &&
                       $context['error'] === 'S3 connection timeout';
            })
            ->once();

        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'bav_verified' => true,
        ]);

        try {
            $this->reportService->generateReport($upload->id);
        } catch (\Exception $e) {
            $this->assertEquals('S3 connection timeout', $e->getMessage());
        }
    }

    public function test_bav_message_generation_for_name_match_yes(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_YES,
            'name_match_score' => 100,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);

        // Should contain success message with actual format
        $this->assertStringContainsString('Name matches account owner', $csvContent);
        $this->assertStringContainsString('score: 100/100', $csvContent);
    }

    public function test_bav_message_generation_for_name_match_partial(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'LT601010012345678901',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_PARTIAL,
            'name_match_score' => 70,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);

        // Should contain partial match message with actual format
        $this->assertStringContainsString('Name partially matches account owner', $csvContent);
        $this->assertStringContainsString('score: 70/100', $csvContent);
    }

    public function test_bav_message_generation_for_name_match_no(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Wrong',
            'last_name' => 'Name',
            'iban' => 'ES9121000418450200051332',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_NO,
            'name_match_score' => 20,
            'vop_score' => 80, // High VOP score so BAV is not skipped
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);

        // Should contain mismatch message with actual format
        $this->assertStringContainsString('Name does not match account owner records', $csvContent);
    }

    public function test_bav_report_includes_all_bav_selected_debtors_regardless_of_validation(): void
    {
        $upload = Upload::factory()->create();

        // Valid debtor with BAV
        $validDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Valid',
            'last_name' => 'User',
            'bav_selected' => true,
            'vop_status' => Debtor::VOP_VERIFIED,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        // Invalid debtor with BAV selected (still included because bav_selected=true)
        $invalidDebtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'InvalidButSelected',
            'last_name' => 'User',
            'bav_selected' => true,
            'vop_status' => Debtor::VOP_VERIFIED,
            'validation_status' => Debtor::VALIDATION_INVALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $validDebtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_YES,
            'bav_verified' => true,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $invalidDebtor->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_NO,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);
        $lines = explode("\n", trim($csvContent));

        // Should include both debtors (header + 2 data rows)
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('Valid', $csvContent);
        $this->assertStringContainsString('InvalidButSelected', $csvContent);
    }

    public function test_bav_report_handles_empty_bav_selection(): void
    {
        $upload = Upload::factory()->create();

        // Create debtors but none with BAV selected
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => false,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);

        // Should only have header, no data rows
        $lines = explode("\n", trim($csvContent));
        $this->assertCount(1, $lines, 'CSV should only contain header when no BAV debtors');
    }

    public function test_generate_vop_report_job_handles_report_generation(): void
    {
        $upload = Upload::factory()->create();

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'bav_verified' => true,
        ]);

        $job = new GenerateVopReportJob($upload->id);
        $job->handle($this->reportService);

        // Assert report was created in S3
        $files = Storage::disk('s3')->files('vop-reports');
        $this->assertNotEmpty($files, 'Report file should be created in S3');

        // Check that the file contains the BAV debtor
        $reportFile = $files[0];
        $content = Storage::disk('s3')->get($reportFile);
        $this->assertNotEmpty($content);
    }

    public function test_bav_report_uses_correct_bav_result_values(): void
    {
        $upload = Upload::factory()->create();

        $debtorYes = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Match',
            'last_name' => 'Yes',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $debtorPartial = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Match',
            'last_name' => 'Partial',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        $debtorNo = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Match',
            'last_name' => 'No',
            'bav_selected' => true,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtorYes->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_YES,
            'bav_verified' => true,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtorPartial->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_PARTIAL,
            'bav_verified' => true,
        ]);

        VopLog::factory()->create([
            'debtor_id' => $debtorNo->id,
            'upload_id' => $upload->id,
            'name_match' => VopLog::NAME_MATCH_NO,
            'bav_verified' => true,
        ]);

        $reportPath = $this->reportService->generateReport($upload->id);
        $csvContent = Storage::disk('s3')->get($reportPath);
        $lines = array_map('str_getcsv', explode("\n", trim($csvContent)));

        // Find each debtor in CSV and check bav_result column
        $yesRow = array_filter($lines, fn($row) => isset($row[1]) && $row[1] === 'Yes');
        $partialRow = array_filter($lines, fn($row) => isset($row[1]) && $row[1] === 'Partial');
        $noRow = array_filter($lines, fn($row) => isset($row[1]) && $row[1] === 'No');

        $this->assertNotEmpty($yesRow);
        $this->assertNotEmpty($partialRow);
        $this->assertNotEmpty($noRow);

        // Check bav_result values (column index 3)
        $this->assertEquals('yes', array_values($yesRow)[0][3]);
        $this->assertEquals('partial', array_values($partialRow)[0][3]);
        $this->assertEquals('no', array_values($noRow)[0][3]);
    }
}
