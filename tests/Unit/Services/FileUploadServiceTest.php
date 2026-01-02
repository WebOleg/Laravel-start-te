<?php

/**
 * Unit tests for FileUploadService.
 * 
 * Stage A: FileUploadService accepts rows and skips duplicates/blacklisted
 * Stage B: DebtorValidationService validates remaining records
 */

namespace Tests\Unit\Services;

use App\Models\Debtor;
use App\Services\FileUploadService;
use App\Services\BlacklistService;
use App\Services\DeduplicationService;
use App\Services\IbanValidator;
use App\Services\SpreadsheetParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function createService(): FileUploadService
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $deduplicationService = app(DeduplicationService::class);

        return new FileUploadService(
            new SpreadsheetParserService(),
            $ibanValidator,
            $blacklistService,
            $deduplicationService
        );
    }

    public function test_process_skips_blacklisted_iban(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped']['blacklisted']);
    }

    public function test_process_accepts_clean_iban(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']['total']);
    }

    public function test_process_saves_raw_data(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount,custom\nJohn,Doe,DE89370400440532013000,100.00,extra";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $debtor = Debtor::first();
        $this->assertNotNull($debtor->raw_data);
        $this->assertEquals('extra', $debtor->raw_data['custom']);
    }

    public function test_process_saves_headers_to_upload(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $upload = $result['upload'];
        $this->assertIsArray($upload->headers);
        $this->assertContains('first_name', $upload->headers);
    }

    public function test_process_returns_skipped_rows_info(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertNotEmpty($result['skipped']);
        $this->assertArrayHasKey('skipped_rows', $result['upload']->meta);
    }
}
