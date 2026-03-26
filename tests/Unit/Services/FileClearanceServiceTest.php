<?php

/**
 * Unit tests for FileClearanceService.
 */

namespace Tests\Unit\Services;

use App\Services\BlacklistService;
use App\Services\FileClearanceService;
use App\Services\FilePreValidationService;
use App\Services\IbanApiService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileClearanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileClearanceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        // Default service with real FilePreValidationService (used by non-validateAndStore tests)
        $this->service = new FileClearanceService(
            app(FilePreValidationService::class),
        );
    }

    /**
     * Build a FileClearanceService with a mocked FilePreValidationService
     * so we control what headers/sample_count are returned.
     */
    private function serviceWithPreValidation(array $headers, int $sampleCount = 5): FileClearanceService
    {
        $mock = $this->createMock(FilePreValidationService::class);
        $mock->method('validate')->willReturn([
            'headers'      => $headers,
            'sample_count' => $sampleCount,
        ]);

        return new FileClearanceService($mock);
    }

    // ══════════════════════════════════════════════════════════════
    // validateAndStore() — Header Detection & IBAN Requirement
    // ══════════════════════════════════════════════════════════════

    public function test_validate_and_store_accepts_file_with_iban_column(): void
    {
        $svc = $this->serviceWithPreValidation(['first_name', 'last_name', 'iban', 'bic'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "first_name,last_name,iban,bic\nJohn,Doe,DE89370400440532013000,COBADEFFXXX\n");

        $result = $svc->validateAndStore($file);

        $this->assertArrayHasKey('s3_path', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('header_meta', $result);
        $this->assertArrayHasKey('total_rows', $result);
        $this->assertContains('iban', $result['headers']);
    }

    public function test_validate_and_store_detects_iban_number_variant(): void
    {
        $svc = $this->serviceWithPreValidation(['name', 'iban_number'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "name,iban_number\nJohn,DE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('iban_number', $result['header_meta']['iban_header']);
    }

    public function test_validate_and_store_detects_bank_account_variant(): void
    {
        $svc = $this->serviceWithPreValidation(['name', 'bank_account'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "name,bank_account\nJohn,DE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('bank_account', $result['header_meta']['iban_header']);
    }

    public function test_validate_and_store_detects_account_number_variant(): void
    {
        $svc = $this->serviceWithPreValidation(['name', 'account_number'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "name,account_number\nJohn,DE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('account_number', $result['header_meta']['iban_header']);
    }

    public function test_validate_and_store_throws_when_iban_column_missing(): void
    {
        $svc = $this->serviceWithPreValidation(['first_name', 'last_name', 'email'], 1);
        $file = UploadedFile::fake()->createWithContent('no_iban.csv', "first_name,last_name,email\nJohn,Doe,john@test.com\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required column: IBAN');

        $svc->validateAndStore($file);
    }

    public function test_validate_and_store_throws_for_empty_file(): void
    {
        $svc = $this->serviceWithPreValidation([], 0);
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $this->expectException(\InvalidArgumentException::class);

        $svc->validateAndStore($file);
    }

    public function test_validate_and_store_throws_for_headers_only_file(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'name'], 0);
        $file = UploadedFile::fake()->createWithContent('headers_only.csv', "iban,name\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no data rows');

        $svc->validateAndStore($file);
    }

    // ══════════════════════════════════════════════════════════════
    // validateAndStore() — Header Meta Detection
    // ══════════════════════════════════════════════════════════════

    public function test_validate_and_store_detects_bic_header(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'bic'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban,bic\nDE89370400440532013000,COBADEFFXXX\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('bic', $result['header_meta']['bic_header']);
    }

    public function test_validate_and_store_detects_swift_as_bic(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'swift'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban,swift\nDE89370400440532013000,COBADEFFXXX\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('swift', $result['header_meta']['bic_header']);
    }

    public function test_validate_and_store_detects_name_headers(): void
    {
        $svc = $this->serviceWithPreValidation(['first_name', 'last_name', 'iban'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "first_name,last_name,iban\nJohn,Doe,DE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('first_name', $result['header_meta']['first_name_header']);
        $this->assertEquals('last_name', $result['header_meta']['last_name_header']);
    }

    public function test_validate_and_store_detects_full_name_header(): void
    {
        $svc = $this->serviceWithPreValidation(['full_name', 'iban'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "full_name,iban\nJohn Doe,DE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('full_name', $result['header_meta']['name_header']);
    }

    public function test_validate_and_store_detects_email_header(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'email'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban,email\nDE89370400440532013000,john@test.com\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('email', $result['header_meta']['email_header']);
    }

    public function test_validate_and_store_sets_null_for_missing_optional_headers(): void
    {
        $svc = $this->serviceWithPreValidation(['iban'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban\nDE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertNull($result['header_meta']['bic_header']);
        $this->assertNull($result['header_meta']['first_name_header']);
        $this->assertNull($result['header_meta']['last_name_header']);
        $this->assertNull($result['header_meta']['name_header']);
        $this->assertNull($result['header_meta']['email_header']);
    }

    // ══════════════════════════════════════════════════════════════
    // validateAndStore() — S3 Storage
    // ══════════════════════════════════════════════════════════════

    public function test_validate_and_store_stores_file_on_s3(): void
    {
        $svc = $this->serviceWithPreValidation(['iban'], 1);
        $file = UploadedFile::fake()->createWithContent('upload.csv', "iban\nDE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertNotEmpty($result['s3_path']);
        Storage::disk('s3')->assertExists($result['s3_path']);
    }

    public function test_validate_and_store_stores_in_clearance_directory(): void
    {
        $svc = $this->serviceWithPreValidation(['iban'], 1);
        $file = UploadedFile::fake()->createWithContent('test.csv', "iban\nDE89370400440532013000\n");

        $result = $svc->validateAndStore($file);

        $this->assertStringStartsWith('clearance/', $result['s3_path']);
    }

    // ══════════════════════════════════════════════════════════════
    // validateAndStore() — Row Counting
    // ══════════════════════════════════════════════════════════════

    public function test_validate_and_store_counts_data_rows_correctly(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'name'], 3);
        $file = UploadedFile::fake()->createWithContent('multi.csv', "iban,name\nDE89370400440532013000,John\nFR7630006000011234567890189,Jane\nES9121000418450200051332,Bob\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals(3, $result['total_rows']);
    }

    public function test_validate_and_store_handles_semicolon_delimited_csv(): void
    {
        $svc = $this->serviceWithPreValidation(['iban', 'name', 'bic'], 2);
        $file = UploadedFile::fake()->createWithContent('semicolon.csv', "iban;name;bic\nDE89370400440532013000;John Doe;COBADEFFXXX\nFR7630006000011234567890189;Jane Smith;BNPAFRPPXXX\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals(2, $result['total_rows']);
        $this->assertEquals('iban', $result['header_meta']['iban_header']);
    }

    // ══════════════════════════════════════════════════════════════
    // validateAndStore() — Case-Insensitive Headers
    // ══════════════════════════════════════════════════════════════

    public function test_validate_and_store_handles_uppercase_headers(): void
    {
        $svc = $this->serviceWithPreValidation(['IBAN', 'BIC', 'NAME'], 1);
        $file = UploadedFile::fake()->createWithContent('upper.csv', "IBAN,BIC,NAME\nDE89370400440532013000,COBADEFFXXX,John\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('IBAN', $result['header_meta']['iban_header']);
    }

    public function test_validate_and_store_handles_mixed_case_headers(): void
    {
        $svc = $this->serviceWithPreValidation(['Iban', 'First_Name', 'Last_Name'], 1);
        $file = UploadedFile::fake()->createWithContent('mixed.csv', "Iban,First_Name,Last_Name\nDE89370400440532013000,John,Doe\n");

        $result = $svc->validateAndStore($file);

        $this->assertEquals('Iban', $result['header_meta']['iban_header']);
    }

    // ══════════════════════════════════════════════════════════════
    // downloadFromS3()
    // ══════════════════════════════════════════════════════════════

    public function test_download_from_s3_creates_temp_file(): void
    {
        Storage::disk('s3')->put('clearance/test.csv', "iban\nDE89370400440532013000\n");

        $tempPath = $this->service->downloadFromS3('clearance/test.csv');

        $this->assertFileExists($tempPath);
        $this->assertStringContainsString('clearance_', basename($tempPath));
        $this->assertStringEndsWith('.csv', $tempPath);

        @unlink($tempPath);
    }

    public function test_download_from_s3_preserves_file_content(): void
    {
        $content = "iban,bic\nDE89370400440532013000,COBADEFFXXX\nFR7630006000011234567890189,BNPAFRPPXXX\n";
        Storage::disk('s3')->put('clearance/data.csv', $content);

        $tempPath = $this->service->downloadFromS3('clearance/data.csv');

        $this->assertEquals($content, file_get_contents($tempPath));

        @unlink($tempPath);
    }

    public function test_download_from_s3_throws_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found in S3');

        $this->service->downloadFromS3('clearance/nonexistent.csv');
    }

    // ══════════════════════════════════════════════════════════════
    // deleteFromS3()
    // ══════════════════════════════════════════════════════════════

    public function test_delete_from_s3_removes_file(): void
    {
        Storage::disk('s3')->put('clearance/to_delete.csv', 'data');

        $this->service->deleteFromS3('clearance/to_delete.csv');

        Storage::disk('s3')->assertMissing('clearance/to_delete.csv');
    }

    public function test_delete_from_s3_does_not_throw_for_missing_file(): void
    {
        // Should not throw — silently logs warning
        $this->service->deleteFromS3('clearance/nonexistent.csv');

        $this->assertTrue(true); // No exception means pass
    }

    // ══════════════════════════════════════════════════════════════
    // streamRows() — CSV
    // ══════════════════════════════════════════════════════════════

    public function test_stream_rows_yields_all_csv_rows(): void
    {
        $csv = "iban,name\nDE89370400440532013000,John\nFR7630006000011234567890189,Jane\n";
        $tempPath = tempnam(sys_get_temp_dir(), 'stream_test_') . '.csv';
        file_put_contents($tempPath, $csv);

        $rows = [];
        foreach ($this->service->streamRows($tempPath, ['iban', 'name']) as [$index, $row]) {
            $rows[] = ['index' => $index, 'row' => $row];
        }

        $this->assertCount(2, $rows);
        $this->assertEquals(0, $rows[0]['index']);
        $this->assertEquals('DE89370400440532013000', $rows[0]['row']['iban']);
        $this->assertEquals(1, $rows[1]['index']);
        $this->assertEquals('FR7630006000011234567890189', $rows[1]['row']['iban']);

        @unlink($tempPath);
    }

    public function test_stream_rows_handles_semicolon_csv(): void
    {
        $csv = "iban;name\nDE89370400440532013000;John\n";
        $tempPath = tempnam(sys_get_temp_dir(), 'stream_semi_') . '.csv';
        file_put_contents($tempPath, $csv);

        $rows = [];
        foreach ($this->service->streamRows($tempPath, ['iban', 'name']) as [$index, $row]) {
            $rows[] = $row;
        }

        $this->assertCount(1, $rows);
        $this->assertEquals('DE89370400440532013000', $rows[0]['iban']);

        @unlink($tempPath);
    }

    public function test_stream_rows_throws_for_unsupported_extension(): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'stream_bad_') . '.json';
        file_put_contents($tempPath, '{}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file type');

        // Must iterate to trigger generator
        foreach ($this->service->streamRows($tempPath, ['iban']) as $row) {
            // noop
        }

        @unlink($tempPath);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — Valid Rows
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_returns_cleared_row_for_valid_iban(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->method('isNameBlacklisted')->willReturn(false);
        $blacklist->method('isEmailBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000', 'name' => 'John Doe'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => 'name',
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNotNull($result['row']);
        $this->assertNull($result['excluded']);
        $this->assertTrue($result['vop_resolved']);
        $this->assertFalse($result['vop_failed']);
        $this->assertEquals('COBADEFFXXX', $result['row']['_resolved_bic']);
    }

    public function test_process_row_overwrites_bic_when_bic_header_exists(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('NEWBICXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->method('isNameBlacklisted')->willReturn(false);
        $blacklist->method('isEmailBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000', 'bic' => 'OLDBICXXX'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => 'bic',
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNotNull($result['row']);
        $this->assertEquals('NEWBICXXX', $result['row']['bic']);
        $this->assertArrayNotHasKey('_resolved_bic', $result['row']);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — Missing / Invalid IBAN
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_excludes_row_with_empty_iban(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanValidator = $this->createMock(IbanValidator::class);
        $blacklist = $this->createMock(BlacklistService::class);

        $row = ['iban' => '', 'name' => 'John'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertNotNull($result['excluded']);
        $this->assertContains('Missing IBAN', $result['excluded']['reasons']);
    }

    public function test_process_row_excludes_row_with_invalid_iban(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => false,
            'is_sepa' => false,
            'errors' => ['Invalid checksum'],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);

        $row = ['iban' => 'INVALIDIBAN123'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertNotNull($result['excluded']);
        $this->assertStringContainsString('Invalid IBAN', $result['excluded']['reasons'][0]);
    }

    public function test_process_row_excludes_non_sepa_iban(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => false,
            'country_code' => 'US',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);

        $row = ['iban' => 'US12345678901234567'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertStringContainsString('not in SEPA zone', $result['excluded']['reasons'][0]);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — VOP Resolution
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_falls_back_to_file_bic_when_vop_fails(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn(null);

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->method('isNameBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000', 'bic' => 'FALLBACKBIC'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => 'bic',
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNotNull($result['row']);
        $this->assertFalse($result['vop_resolved']);
        $this->assertTrue($result['vop_failed']);
        $this->assertEquals('FALLBACKBIC', $result['row']['bic']);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — Blacklist Checks
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_excludes_blacklisted_iban(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(true);
        $blacklist->method('isBicBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('IBAN is blacklisted', $result['excluded']['reasons']);
    }

    public function test_process_row_excludes_blacklisted_bic(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('BLACKLISTEDBIC');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(true);

        $row = ['iban' => 'DE89370400440532013000'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('BIC is blacklisted', $result['excluded']['reasons']);
    }

    public function test_process_row_excludes_blacklisted_name(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->method('isNameBlacklisted')->willReturn(true);

        $row = ['iban' => 'DE89370400440532013000', 'first_name' => 'John', 'last_name' => 'Fraud'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => 'first_name',
            'last_name_header' => 'last_name',
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('Name is blacklisted', $result['excluded']['reasons']);
    }

    public function test_process_row_excludes_blacklisted_email(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->method('isNameBlacklisted')->willReturn(false);
        $blacklist->method('isEmailBlacklisted')->willReturn(true);

        $row = ['iban' => 'DE89370400440532013000', 'email' => 'fraud@test.com'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => 'email',
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('Email is blacklisted', $result['excluded']['reasons']);
    }

    public function test_process_row_collects_multiple_blacklist_reasons(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('BLACKBIC');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(true);
        $blacklist->method('isBicBlacklisted')->willReturn(true);
        $blacklist->method('isNameBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertCount(2, $result['excluded']['reasons']);
        $this->assertContains('IBAN is blacklisted', $result['excluded']['reasons']);
        $this->assertContains('BIC is blacklisted', $result['excluded']['reasons']);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — BIC blacklist with VOP vs file fallback
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_checks_vop_bic_against_blacklist(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('VOPBLACKLISTED');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->with('VOPBLACKLISTED')->willReturn(true);
        $blacklist->method('isNameBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000', 'bic' => 'FILEBICOK'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => 'bic',
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('BIC is blacklisted', $result['excluded']['reasons']);
    }

    public function test_process_row_checks_file_bic_against_blacklist_when_vop_fails(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn(null);

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->with('FILEBLACKLISTED')->willReturn(true);
        $blacklist->method('isNameBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000', 'bic' => 'FILEBLACKLISTED'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => 'bic',
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertTrue($result['vop_failed']);
        $this->assertContains('BIC is blacklisted', $result['excluded']['reasons']);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — Name from full_name field
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_splits_full_name_for_blacklist_check(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(false);
        $blacklist->method('isBicBlacklisted')->willReturn(false);
        $blacklist->expects($this->once())
            ->method('isNameBlacklisted')
            ->with('John', 'Doe')
            ->willReturn(true);

        $row = ['iban' => 'DE89370400440532013000', 'name' => 'John Doe'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => 'name',
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $this->assertNull($result['row']);
        $this->assertContains('Name is blacklisted', $result['excluded']['reasons']);
    }

    // ══════════════════════════════════════════════════════════════
    // processRow() — IBAN Masking in Exclusion
    // ══════════════════════════════════════════════════════════════

    public function test_process_row_masks_iban_in_excluded_details(): void
    {
        $ibanApi = $this->createMock(IbanApiService::class);
        $ibanApi->method('getBic')->willReturn('COBADEFFXXX');

        $ibanValidator = $this->createMock(IbanValidator::class);
        $ibanValidator->method('normalize')->willReturnArgument(0);
        $ibanValidator->method('validate')->willReturn([
            'valid' => true,
            'is_sepa' => true,
            'country_code' => 'DE',
            'errors' => [],
        ]);

        $blacklist = $this->createMock(BlacklistService::class);
        $blacklist->method('isBlacklisted')->willReturn(true);
        $blacklist->method('isBicBlacklisted')->willReturn(false);

        $row = ['iban' => 'DE89370400440532013000'];
        $headerMeta = [
            'iban_header' => 'iban',
            'bic_header' => null,
            'first_name_header' => null,
            'last_name_header' => null,
            'name_header' => null,
            'email_header' => null,
        ];

        $result = $this->service->processRow($row, 1, $headerMeta, $ibanApi, $ibanValidator, $blacklist);

        $maskedIban = $result['excluded']['iban'];
        $this->assertStringStartsWith('DE89', $maskedIban);
        $this->assertStringEndsWith('3000', $maskedIban);
        $this->assertStringContainsString('****', $maskedIban);
        $this->assertNotEquals('DE89370400440532013000', $maskedIban);
    }

    // ══════════════════════════════════════════════════════════════
    // CSV Writer — writes to temp, uploads to S3 on close
    // ══════════════════════════════════════════════════════════════

    public function test_open_csv_writer_creates_temp_file_with_bom_and_headers(): void
    {
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter(['iban', 'bic', 'name'], 'original.csv');

        $this->assertIsResource($handle);
        $this->assertFileExists($tempPath);
        $this->assertStringContainsString('original_cleared_', $fileName);
        $this->assertStringEndsWith('.csv', $fileName);

        // Close without uploading to S3 — just fclose for this unit test
        fclose($handle);

        $content = file_get_contents($tempPath);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // BOM
        $this->assertStringContainsString('iban,bic,name', $content);

        @unlink($tempPath);
    }

    public function test_write_csv_row_writes_correct_data(): void
    {
        $headers = ['iban', 'name'];
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter($headers, 'test.csv');

        $this->service->writeCsvRow($handle, $headers, ['iban' => 'DE89370400440532013000', 'name' => 'John'], false);

        // Close without S3 upload for content inspection
        fclose($handle);

        $content = file_get_contents($tempPath);
        $this->assertStringContainsString('DE89370400440532013000,John', $content);

        @unlink($tempPath);
    }

    public function test_write_csv_row_uses_resolved_bic_when_injected(): void
    {
        $headers = ['iban', 'bic', 'name'];
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter($headers, 'test.csv');

        $row = [
            'iban' => 'DE89370400440532013000',
            'name' => 'John',
            '_resolved_bic' => 'COBADEFFXXX',
        ];

        $this->service->writeCsvRow($handle, $headers, $row, true);

        fclose($handle);

        $content = file_get_contents($tempPath);
        $this->assertStringContainsString('COBADEFFXXX', $content);

        @unlink($tempPath);
    }

    public function test_csv_writer_handles_multiple_rows(): void
    {
        $headers = ['iban', 'name'];
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter($headers, 'multi.csv');

        $this->service->writeCsvRow($handle, $headers, ['iban' => 'DE89370400440532013000', 'name' => 'John'], false);
        $this->service->writeCsvRow($handle, $headers, ['iban' => 'FR7630006000011234567890189', 'name' => 'Jane'], false);
        $this->service->writeCsvRow($handle, $headers, ['iban' => 'ES9121000418450200051332', 'name' => 'Bob'], false);

        fclose($handle);

        $lines = file($tempPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        // 1 header + 3 data rows (first line has BOM so count from content)
        $this->assertCount(4, $lines);

        @unlink($tempPath);
    }

    public function test_close_csv_writer_uploads_to_s3(): void
    {
        $headers = ['iban', 'name'];
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter($headers, 'test.csv');

        $this->service->writeCsvRow($handle, $headers, ['iban' => 'DE89370400440532013000', 'name' => 'John'], false);

        $s3Path = $this->service->closeCsvWriter($handle, $tempPath, $fileName);

        $this->assertStringStartsWith('clearance/results/', $s3Path);
        $this->assertStringEndsWith($fileName, $s3Path);
        Storage::disk('s3')->assertExists($s3Path);

        // Temp file should be cleaned up
        $this->assertFileDoesNotExist($tempPath);
    }

    public function test_close_csv_writer_s3_content_matches(): void
    {
        $headers = ['iban', 'name'];
        [$handle, $tempPath, $fileName] = $this->service->openCsvWriter($headers, 'verify.csv');

        $this->service->writeCsvRow($handle, $headers, ['iban' => 'DE89370400440532013000', 'name' => 'John'], false);

        $s3Path = $this->service->closeCsvWriter($handle, $tempPath, $fileName);

        $s3Content = Storage::disk('s3')->get($s3Path);
        $this->assertStringContainsString('iban,name', $s3Content);
        $this->assertStringContainsString('DE89370400440532013000,John', $s3Content);
    }
}
