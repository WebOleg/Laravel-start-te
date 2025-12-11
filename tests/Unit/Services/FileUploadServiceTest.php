<?php

/**
 * Unit tests for FileUploadService with blacklist.
 */

namespace Tests\Unit\Services;

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

    public function test_process_rejects_blacklisted_iban(): void
    {
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

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['failed']);
        $this->assertStringContainsString('blacklisted', $result['errors'][0]['message']);
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
}
