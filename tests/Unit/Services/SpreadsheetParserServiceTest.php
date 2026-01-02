<?php

/**
 * Unit tests for SpreadsheetParserService.
 */

namespace Tests\Unit\Services;

use App\Services\SpreadsheetParserService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

class SpreadsheetParserServiceTest extends TestCase
{
    private SpreadsheetParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new SpreadsheetParserService();
    }

    public function test_parses_csv_with_comma_delimiter(): void
    {
        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.50";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(['first_name', 'last_name', 'iban', 'amount'], $result['headers']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals('John', $result['rows'][0]['first_name']);
        $this->assertEquals('DE89370400440532013000', $result['rows'][0]['iban']);

        unlink($path);
    }

    public function test_parses_csv_with_semicolon_delimiter(): void
    {
        $content = "first_name;last_name;iban;amount\nHans;Mueller;DE89370400440532013000;250,00";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(['first_name', 'last_name', 'iban', 'amount'], $result['headers']);
        $this->assertEquals('Hans', $result['rows'][0]['first_name']);

        unlink($path);
    }

    public function test_normalizes_headers_to_snake_case(): void
    {
        $content = "First Name,Last Name,IBAN Number,Total Amount\nJohn,Doe,DE89370400440532013000,100";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(['first_name', 'last_name', 'iban_number', 'total_amount'], $result['headers']);

        unlink($path);
    }

    public function test_handles_empty_values(): void
    {
        $content = "first_name,last_name,email\nJohn,Doe,\nJane,,jane@test.com";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertNull($result['rows'][0]['email']);
        $this->assertNull($result['rows'][1]['last_name']);

        unlink($path);
    }

    public function test_skips_empty_rows(): void
    {
        $content = "first_name,last_name\nJohn,Doe\n\n\nJane,Smith";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertCount(2, $result['rows']);

        unlink($path);
    }

    public function test_returns_total_rows_count(): void
    {
        $content = "name,amount\nA,100\nB,200\nC,300";
        $path = $this->createTempCsvFile($content);

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(3, $result['total_rows']);

        unlink($path);
    }

    public function test_parses_excel_with_temp_file(): void
    {
        $path = $this->createTempExcelFile([
            ['First Name', 'Last Name', 'IBAN', 'Amount'],
            ['John', 'Doe', 'DE89370400440532013000', 100.50],
            ['Jane', 'Smith', 'ES9121000418450200051332', 200.00],
        ]);

        $result = $this->parser->parseExcel($path);

        $this->assertEquals(['first_name', 'last_name', 'iban', 'amount'], $result['headers']);
        $this->assertCount(2, $result['rows']);
        $this->assertEquals('John', $result['rows'][0]['first_name']);
        $this->assertEquals('DE89370400440532013000', $result['rows'][0]['iban']);
        $this->assertEquals('Jane', $result['rows'][1]['first_name']);
        $this->assertEquals(2, $result['total_rows']);

        unlink($path);
    }

    public function test_excel_handles_empty_values(): void
    {
        $path = $this->createTempExcelFile([
            ['first_name', 'last_name', 'email'],
            ['John', 'Doe', null],
            ['Jane', null, 'jane@test.com'],
        ]);

        $result = $this->parser->parseExcel($path);

        $this->assertNull($result['rows'][0]['email']);
        $this->assertNull($result['rows'][1]['last_name']);

        unlink($path);
    }

    public function test_excel_skips_empty_rows(): void
    {
        $path = $this->createTempExcelFile([
            ['first_name', 'last_name'],
            ['John', 'Doe'],
            [null, null],
            ['Jane', 'Smith'],
        ]);

        $result = $this->parser->parseExcel($path);

        $this->assertCount(2, $result['rows']);

        unlink($path);
    }

    private function createTempCsvFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.csv';
        file_put_contents($path, $content);
        return $path;
    }

    private function createTempExcelFile(array $data): string
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
}
