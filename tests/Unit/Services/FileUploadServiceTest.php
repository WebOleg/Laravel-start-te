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
use App\Models\TetherInstance;
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
        $debtorImportService = new DebtorImportService(
            new IbanValidator(),
            app(DeduplicationService::class)
        );

        return new FileUploadService(
            new SpreadsheetParserService(),
            $debtorImportService
        );
    }

    private function createInstanceWithAccount(?EmpAccount $account = null): TetherInstance
    {
        $account = $account ?? EmpAccount::factory()->create();

        return TetherInstance::create([
            'name' => $account->name ?? 'Test Instance',
            'slug' => 'instance-' . $account->id,
            'acquirer_type' => 'emp',
            'acquirer_account_id' => $account->id,
            'is_active' => true,
            'status' => 'active',
            'sort_order' => 0,
        ]);
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

    public function test_process_with_global_lock_skips_cross_instance_paid_iban(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $instanceB = $this->createInstanceWithAccount($accountB);
        $iban = 'DE_LOCKED_123';

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);
        $debtorA = Debtor::factory()->create([
            'upload_id' => $uploadA->id,
            'iban' => $iban,
            'tether_instance_id' => $instanceA->id,
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $service = $this->createService();
        $content = "name,iban,amount\nNew User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('locked.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountB->id,
            applyGlobalLock: true,
            tetherInstanceId: $instanceB->id
        );

        $this->assertEquals(0, $result['created'], 'Should not create debtor');
        $this->assertEquals(1, $result['skipped']['total'], 'Should count as skipped');
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString("Locked to TetherInstance #{$instanceA->id}", $result['errors'][0]['error']);
    }

    public function test_process_with_global_lock_allows_same_instance_paid_iban(): void
    {
        $accountA = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $iban = 'DE_SAME_123';

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);
        $debtorA = Debtor::factory()->create([
            'upload_id' => $uploadA->id,
            'iban' => $iban,
            'tether_instance_id' => $instanceA->id,
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $service = $this->createService();
        $content = "name,iban,amount\nReturning User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('renewal.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountA->id,
            applyGlobalLock: true,
            tetherInstanceId: $instanceA->id
        );

        $this->assertEquals(1, $result['created'], 'Should allow re-upload to same instance');
        $this->assertEquals(0, $result['skipped']['total']);
    }

    public function test_process_without_global_lock_allows_cross_instance_paid_iban(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $instanceB = $this->createInstanceWithAccount($accountB);
        $iban = 'DE_CROSS_123';

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);
        $debtorA = Debtor::factory()->create([
            'upload_id' => $uploadA->id,
            'iban' => $iban,
            'tether_instance_id' => $instanceA->id,
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtorA->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $service = $this->createService();
        $content = "name,iban,amount\nNew User,{$iban},100";
        $file = UploadedFile::fake()->createWithContent('allowed.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountB->id,
            applyGlobalLock: false,
            tetherInstanceId: $instanceB->id
        );

        $this->assertEquals(1, $result['created'], 'Should allow cross-instance when lock is off');
        $this->assertEquals(0, $result['skipped']['total']);
    }
}
