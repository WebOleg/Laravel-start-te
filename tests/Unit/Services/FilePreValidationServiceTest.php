<?php

namespace Tests\Unit\Services;

use App\Services\FilePreValidationService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class FilePreValidationServiceTest extends TestCase
{
    private FilePreValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FilePreValidationService();
    }

    // --- validateHeaders() unit tests ---

    public function test_valid_headers_pass(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amount']);

        $this->assertEmpty($result['errors']);
        $this->assertEmpty($result['warnings']);
        $this->assertEmpty($result['suggestions']);
    }

    public function test_valid_headers_with_aliases_pass(): void
    {
        $result = $this->service->validateHeaders(['fullname', 'bank_account', 'sum', 'email']);

        $this->assertEmpty($result['errors']);
    }

    public function test_missing_iban_header_returns_error(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'amount']);

        $this->assertContains('Missing required header: IBAN.', $result['errors']);
    }

    public function test_missing_amount_header_returns_error(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban']);

        $this->assertContains('Missing required header: amount.', $result['errors']);
    }

    public function test_missing_all_name_headers_returns_error(): void
    {
        $result = $this->service->validateHeaders(['iban', 'amount', 'email']);

        $this->assertContains('Missing required header: name.', $result['errors']);
    }

    public function test_misspelled_header_as_only_name_header_returns_error_and_suggestion(): void
    {
        // "lst_name" has no other name header to fall back on
        $result = $this->service->validateHeaders(['first_name', 'lst_name', 'iban', 'amount']);

        // first_name covers the name group — so no error, but should warn
        $this->assertEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertArrayHasKey('lst_name', $result['suggestions']);
        $this->assertEquals('last_name', $result['suggestions']['lst_name']);
    }

    public function test_misspelled_header_without_any_valid_name_returns_error_and_suggestion(): void
    {
        // Only misspelled name headers — no valid name column
        $result = $this->service->validateHeaders(['frst_name', 'lst_name', 'iban', 'amount']);

        $this->assertContains('Missing required header: name.', $result['errors']);
        $this->assertNotEmpty($result['suggestions']);
    }

    public function test_misspelled_amount_returns_error_and_suggestion(): void
    {
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amout']);

        $this->assertContains('Missing required header: amount.', $result['errors']);
        $this->assertArrayHasKey('amout', $result['suggestions']);
        $this->assertEquals('amount', $result['suggestions']['amout']);
    }

    public function test_completely_unknown_header_no_suggestion(): void
    {
        // "foobar" is far from any known header
        $result = $this->service->validateHeaders(['first_name', 'last_name', 'iban', 'amount', 'foobar']);

        $this->assertEmpty($result['errors']);
        $this->assertArrayNotHasKey('foobar', $result['suggestions']);
    }

    public function test_multiple_missing_required_headers(): void
    {
        $result = $this->service->validateHeaders(['email', 'city']);

        $this->assertCount(3, $result['errors']);
        $this->assertContains('Missing required header: IBAN.', $result['errors']);
        $this->assertContains('Missing required header: amount.', $result['errors']);
        $this->assertContains('Missing required header: name.', $result['errors']);
    }

    public function test_empty_header_is_ignored(): void
    {
        $result = $this->service->validateHeaders(['first_name', '', 'iban', 'amount']);

        $this->assertEmpty($result['errors']);
    }

    // --- Full CSV file validation tests ---

    public function test_valid_csv_file_passes(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00");
        $file = $this->createUploadedFile($path, 'valid.csv');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertGreaterThan(0, $result['sample_count']);

        unlink($path);
    }

    public function test_csv_with_misspelled_header_returns_warnings_and_suggestions(): void
    {
        $path = $this->createTempCsv("first_name,lst_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00");
        $file = $this->createUploadedFile($path, 'typo.csv');

        $result = $this->service->validate($file);

        // first_name covers the name requirement, so file is valid but with warnings
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertEquals('last_name', $result['suggestions']['lst_name']);

        unlink($path);
    }

    public function test_csv_missing_all_required_returns_422_errors(): void
    {
        $path = $this->createTempCsv("foo,bar\nbaz,qux");
        $file = $this->createUploadedFile($path, 'bad.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertCount(3, $result['errors']);

        unlink($path);
    }

    public function test_empty_csv_returns_error(): void
    {
        $path = $this->createTempCsv('');
        $file = $this->createUploadedFile($path, 'empty.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('File is empty or has no headers.', $result['errors']);

        unlink($path);
    }

    public function test_csv_headers_only_no_data_rows(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\n");
        $file = $this->createUploadedFile($path, 'headers_only.csv');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('File has headers but no data rows.', $result['errors']);

        unlink($path);
    }

    public function test_semicolon_delimited_csv_passes(): void
    {
        $path = $this->createTempCsv("first_name;last_name;iban;amount\nJohn;Doe;DE89370400440532013000;100.00");
        $file = $this->createUploadedFile($path, 'semicolon.csv');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($path);
    }

    public function test_excel_file_with_valid_headers_passes(): void
    {
        $path = $this->createTempExcel([
            ['First Name', 'Last Name', 'IBAN', 'Amount'],
            ['John', 'Doe', 'DE89370400440532013000', 100.00],
        ]);
        $file = $this->createUploadedFile($path, 'valid.xlsx');

        $result = $this->service->validate($file);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);

        unlink($path);
    }

    public function test_excel_file_with_misspelled_header_returns_suggestion(): void
    {
        $path = $this->createTempExcel([
            ['First Name', 'Lst Name', 'IBAN', 'Amount'],
            ['John', 'Doe', 'DE89370400440532013000', 100.00],
        ]);
        $file = $this->createUploadedFile($path, 'typo.xlsx');

        $result = $this->service->validate($file);

        // first_name still covers name requirement
        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertArrayHasKey('lst_name', $result['suggestions']);

        unlink($path);
    }

    public function test_unsupported_file_type_returns_error(): void
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.pdf';
        file_put_contents($path, 'dummy');
        $file = $this->createUploadedFile($path, 'bad.pdf');

        $result = $this->service->validate($file);

        $this->assertFalse($result['valid']);
        $this->assertContains('Unsupported file type.', $result['errors']);

        unlink($path);
    }

    public function test_result_always_contains_all_keys(): void
    {
        $path = $this->createTempCsv("first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100");
        $file = $this->createUploadedFile($path, 'valid.csv');

        $result = $this->service->validate($file);

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertArrayHasKey('sample_count', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('suggestions', $result);

        unlink($path);
    }

    // --- Helpers ---

    private function createTempCsv(string $content): string
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    private function createTempExcel(array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($data as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    private function createUploadedFile(string $path, string $name): \Illuminate\Http\UploadedFile
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $mimeTypes = [
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'pdf' => 'application/pdf',
        ];

        return new \Illuminate\Http\UploadedFile(
            $path,
            $name,
            $mimeTypes[$extension] ?? 'application/octet-stream',
            null,
            true
        );
    }
}
