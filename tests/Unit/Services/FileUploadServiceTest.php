<?php

/**
 * Unit tests for FileUploadService.
 *
 * Stage A: FileUploadService accepts rows and skips duplicates/blacklisted
 * Stage B: DebtorValidationService validates remaining records
 */

namespace Tests\Unit\Services;

use App\Enums\BillingModel;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\EmpAccount;
use App\Models\Upload;
use App\Services\BlacklistService;
use App\Services\DebtorImportService;
use App\Services\DeduplicationService;
use App\Services\FileUploadService;
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
        // We use the real container resolution for dependencies to ensure
        // integration with DB-based checks (like DeduplicationService) works as expected.
        $debtorImportService = new DebtorImportService(
            new IbanValidator(),
            app(DeduplicationService::class)
        );

        return new FileUploadService(
            new SpreadsheetParserService(),
            $debtorImportService
        );
    }

    public function test_process_skips_blacklisted_iban(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);

        // Assuming BlacklistService writes to the DB
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
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

    // -------------------------------------------------------------------------
    // NEW TESTS FOR GLOBAL LOCK
    // -------------------------------------------------------------------------

    public function test_process_with_global_lock_skips_cross_account_paid_iban(): void
    {
        // 1. Setup Accounts
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $iban = 'DE_LOCKED_123';

        // 2. Setup existing PAID debtor on Account A
        $uploadA = Upload::factory()->create(['emp_account_id' => $accountA->id]);
        $debtorA = Debtor::factory()->create(['upload_id' => $uploadA->id, 'iban' => $iban]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED
        ]);

        // 3. Action: Upload same IBAN to Account B with Lock ENABLED
        $service = $this->createService();
        $content = "name,iban,amount\nNew User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('locked.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountB->id,
            applyGlobalLock: true
        );

        // 4. Assertions
        $this->assertEquals(0, $result['created'], 'Should not create debtor');
        $this->assertEquals(1, $result['skipped']['total'], 'Should count as skipped');

        // Verify the specific error message
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString("Locked to Account {$accountA->id}", $result['errors'][0]['error']);
    }

    public function test_process_with_global_lock_allows_same_account_paid_iban(): void
    {
        // 1. Setup Account A
        $accountA = EmpAccount::factory()->create();
        $iban = 'DE_SAME_123';

        // 2. Setup existing PAID debtor on Account A
        $uploadA = Upload::factory()->create(['emp_account_id' => $accountA->id]);
        $debtorA = Debtor::factory()->create(['upload_id' => $uploadA->id, 'iban' => $iban]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED
        ]);

        // 3. Action: Upload same IBAN to Account A again (e.g. renewal)
        $service = $this->createService();
        $content = "name,iban,amount\nReturning User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('renewal.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountA->id,
            applyGlobalLock: true
        );

        // 4. Assertions
        $this->assertEquals(1, $result['created'], 'Should allow re-upload to same account');
        $this->assertEquals(0, $result['skipped']['total']);
    }

    public function test_process_without_global_lock_allows_cross_account_paid_iban(): void
    {
        // 1. Setup Accounts
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $iban = 'DE_CROSS_123';

        // 2. Setup existing PAID debtor on Account A
        $uploadA = Upload::factory()->create(['emp_account_id' => $accountA->id]);
        $debtorA = Debtor::factory()->create(['upload_id' => $uploadA->id, 'iban' => $iban]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED
        ]);

        // 3. Action: Upload same IBAN to Account B with Lock DISABLED (Default)
        $service = $this->createService();
        $content = "name,iban,amount\nNew User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('allowed.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountB->id,
            applyGlobalLock: false // Explicitly false
        );

        // 4. Assertions
        $this->assertEquals(1, $result['created'], 'Should allow cross-account when lock is off');
        $this->assertEquals(0, $result['skipped']['total']);
    }
}
