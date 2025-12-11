<?php

/**
 * Unit tests for FileUploadService.
 * 
 * Stage A: FileUploadService accepts ALL rows without validation
 * Stage B: DebtorValidationService validates (including blacklist check)
 */

namespace Tests\Unit\Services;

use App\Models\Debtor;
use App\Services\FileUploadService;
use App\Services\BlacklistService;
use App\Services\IbanValidator;
use App\Services\SpreadsheetParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FileUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_accepts_all_rows_including_blacklisted(): void
    {
        // Stage A: Upload accepts ALL rows, blacklist check is in Stage B
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = new FileUploadService(
            new SpreadsheetParserService(),
            $ibanValidator,
            $blacklistService
        );

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        // Row is accepted in Stage A
        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['failed']);

        // Record saved with pending status
        $this->assertDatabaseHas('debtors', [
            'iban' => 'DE89370400440532013000',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
    }

    public function test_process_accepts_clean_iban(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);

        $service = new FileUploadService(
            new SpreadsheetParserService(),
            $ibanValidator,
            $blacklistService
        );

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['failed']);
    }

    public function test_process_saves_raw_data(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);

        $service = new FileUploadService(
            new SpreadsheetParserService(),
            $ibanValidator,
            $blacklistService
        );

        $content = "first_name,last_name,iban,amount,custom\nJohn,Doe,DE89370400440532013000,100.00,extra";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $debtor = Debtor::first();
        $this->assertNotNull($debtor->raw_data);
        $this->assertEquals('extra', $debtor->raw_data['custom']);
    }

    public function test_process_saves_headers_to_upload(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);

        $service = new FileUploadService(
            new SpreadsheetParserService(),
            $ibanValidator,
            $blacklistService
        );

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $upload = $result['upload'];
        $this->assertIsArray($upload->headers);
        $this->assertContains('first_name', $upload->headers);
    }
}
