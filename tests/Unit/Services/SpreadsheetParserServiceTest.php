<?php

/**
 * Unit tests for SpreadsheetParserService.
 */

namespace Tests\Unit\Services;

use App\Services\SpreadsheetParserService;
use Illuminate\Http\UploadedFile;
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
        $path = $this->createTempFile($content, 'csv');

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
        $path = $this->createTempFile($content, 'csv');

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(['first_name', 'last_name', 'iban', 'amount'], $result['headers']);
        $this->assertEquals('Hans', $result['rows'][0]['first_name']);

        unlink($path);
    }

    public function test_normalizes_headers_to_snake_case(): void
    {
        $content = "First Name,Last Name,IBAN Number,Total Amount\nJohn,Doe,DE89370400440532013000,100";
        $path = $this->createTempFile($content, 'csv');

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(['first_name', 'last_name', 'iban_number', 'total_amount'], $result['headers']);

        unlink($path);
    }

    public function test_handles_empty_values(): void
    {
        $content = "first_name,last_name,email\nJohn,Doe,\nJane,,jane@test.com";
        $path = $this->createTempFile($content, 'csv');

        $result = $this->parser->parseCsv($path);

        $this->assertNull($result['rows'][0]['email']);
        $this->assertNull($result['rows'][1]['last_name']);

        unlink($path);
    }

    public function test_skips_empty_rows(): void
    {
        $content = "first_name,last_name\nJohn,Doe\n\n\nJane,Smith";
        $path = $this->createTempFile($content, 'csv');

        $result = $this->parser->parseCsv($path);

        $this->assertCount(2, $result['rows']);

        unlink($path);
    }

    public function test_returns_total_rows_count(): void
    {
        $content = "name,amount\nA,100\nB,200\nC,300";
        $path = $this->createTempFile($content, 'csv');

        $result = $this->parser->parseCsv($path);

        $this->assertEquals(3, $result['total_rows']);

        unlink($path);
    }

    private function createTempFile(string $content, string $extension): string
    {
        $path = sys_get_temp_dir() . '/test_' . uniqid() . '.' . $extension;
        file_put_contents($path, $content);
        return $path;
    }
}
