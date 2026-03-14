<?php

/**
 * Unit tests for FileUploadService.
 *
 * Stage A: FileUploadService accepts rows and skips duplicates/blacklisted
 * Stage B: DebtorValidationService validates remaining records
 */

namespace Tests\Unit\Services;

use App\Enums\BillingModel;
use App\Jobs\ProcessUploadJob;
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
use Illuminate\Support\Facades\Queue;
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

    public function test_process_stores_file_in_s3(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('upload.csv', $content);

        $result = $service->process($file);

        $upload = $result['upload'];
        Storage::disk('s3')->assertExists($upload->file_path);
    }

    public function test_process_sets_billing_model_on_upload(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, billingModel: BillingModel::Legacy);

        $this->assertEquals(BillingModel::Legacy->value, $result['upload']->billing_model);
    }

    public function test_process_sets_status_to_completed_on_success(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(Upload::STATUS_COMPLETED, $result['upload']->status);
        $this->assertNotNull($result['upload']->processing_completed_at);
    }

    public function test_process_maps_alternative_column_names(): void
    {
        $service = $this->createService();

        $content = "firstname,surname,iban_number,total\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
        $this->assertDatabaseHas('debtors', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_process_maps_full_name_column(): void
    {
        $service = $this->createService();

        $content = "full_name,iban,amount\nJohn Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
    }

    public function test_column_mapping_saved_to_upload(): void
    {
        $service = $this->createService();

        $content = "firstname,surname,bank_account,sum\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $upload = $result['upload'];
        $this->assertIsArray($upload->column_mapping);
        $this->assertEquals('first_name', $upload->column_mapping['firstname']);
        $this->assertEquals('last_name', $upload->column_mapping['surname']);
        $this->assertEquals('iban', $upload->column_mapping['bank_account']);
        $this->assertEquals('amount', $upload->column_mapping['sum']);
    }

    public function test_process_normalizes_column_names_with_special_characters(): void
    {
        $service = $this->createService();

        // Headers with spaces and mixed case should still be mapped
        $content = "First Name,Last Name,IBAN,Amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
        $this->assertDatabaseHas('debtors', [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_process_handles_multiple_rows(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\n"
            . "Alice,Anderson,DE89370400440532013000,100.00\n"
            . "Bob,Brown,FR7630006000011234567890189,200.00\n"
            . "Charlie,Clark,GB29NWBK60161331926819,300.00";
        $file = UploadedFile::fake()->createWithContent('multi.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(3, $result['created']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(3, $result['upload']->total_records);
    }

    public function test_process_handles_mix_of_blacklisted_and_clean(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\n"
            . "John,Doe,DE89370400440532013000,100.00\n"
            . "Jane,Smith,FR7630006000011234567890189,200.00";
        $file = UploadedFile::fake()->createWithContent('mixed.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
    }

    public function test_process_async_dispatches_job(): void
    {
        Queue::fake();

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('async.csv', $content);

        $result = $service->processAsync($file);

        $this->assertTrue($result['queued']);
        $this->assertInstanceOf(Upload::class, $result['upload']);
        $this->assertEquals(Upload::STATUS_PENDING, $result['upload']->status);

        Queue::assertPushed(ProcessUploadJob::class, function ($job) use ($result) {
            return $job->upload->id === $result['upload']->id;
        });
    }

    public function test_process_async_saves_column_mapping_and_headers(): void
    {
        Queue::fake();

        $service = $this->createService();

        $content = "firstname,surname,bank_account,sum\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('mapped.csv', $content);

        $result = $service->processAsync($file);

        $upload = $result['upload'];
        $this->assertContains('firstname', $upload->headers);
        $this->assertEquals('first_name', $upload->column_mapping['firstname']);
        $this->assertEquals('last_name', $upload->column_mapping['surname']);
    }

    public function test_process_async_passes_global_lock_flag_in_meta(): void
    {
        Queue::fake();

        $account = EmpAccount::factory()->create();
        $instance = $this->createInstanceWithAccount($account);

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('lock.csv', $content);

        $result = $service->processAsync(
            $file,
            empAccountId: $account->id,
            applyGlobalLock: true,
            tetherInstanceId: $instance->id
        );

        $this->assertTrue($result['upload']->meta['apply_global_lock']);
    }

    public function test_process_resolves_default_emp_account_when_null(): void
    {
        $account = EmpAccount::factory()->create(['is_active' => true]);

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, empAccountId: null);

        // Should have resolved to some emp_account_id (the active one)
        $this->assertNotNull($result['upload']->emp_account_id);
    }

    public function test_process_uses_explicit_emp_account_id(): void
    {
        $account = EmpAccount::factory()->create();

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, empAccountId: $account->id);

        $this->assertEquals($account->id, $result['upload']->emp_account_id);
    }

    public function test_process_resolves_default_tether_instance_when_null(): void
    {
        $account = EmpAccount::factory()->create();
        $instance = $this->createInstanceWithAccount($account);

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, tetherInstanceId: null);

        $this->assertNotNull($result['upload']->tether_instance_id);
    }

    public function test_process_uses_explicit_tether_instance_id(): void
    {
        $account = EmpAccount::factory()->create();
        $instance = $this->createInstanceWithAccount($account);

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, tetherInstanceId: $instance->id);

        $this->assertEquals($instance->id, $result['upload']->tether_instance_id);
    }

    public function test_filter_cross_account_ibans_returns_all_rows_when_null_instance(): void
    {
        $rows = [
            ['iban' => 'DE89370400440532013000', 'name' => 'John'],
            ['iban' => 'FR7630006000011234567890189', 'name' => 'Jane'],
        ];

        $result = FileUploadService::filterCrossAccountIbans($rows, null, ['iban' => 'iban']);

        $this->assertCount(2, $result['rows']);
        $this->assertEquals(0, $result['excluded_count']);
        $this->assertEmpty($result['errors']);
    }

    public function test_filter_cross_account_ibans_returns_all_rows_when_no_iban_mapping(): void
    {
        $rows = [
            ['name' => 'John', 'amount' => '100'],
        ];

        // No iban in the mapping
        $result = FileUploadService::filterCrossAccountIbans($rows, 1, ['name' => 'name']);

        $this->assertCount(1, $result['rows']);
        $this->assertEquals(0, $result['excluded_count']);
    }

    public function test_filter_cross_account_ibans_returns_all_rows_when_ibans_empty(): void
    {
        $rows = [
            ['iban' => '', 'name' => 'John'],
            ['iban' => null, 'name' => 'Jane'],
        ];

        $result = FileUploadService::filterCrossAccountIbans($rows, 1, ['iban' => 'iban']);

        $this->assertCount(2, $result['rows']);
        $this->assertEquals(0, $result['excluded_count']);
    }

    public function test_filter_cross_account_ibans_excludes_multiple_locked_ibans(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $instanceB = $this->createInstanceWithAccount($accountB);

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);

        // Create two debtors with approved billing on instance A
        foreach (['IBAN_1', 'IBAN_2'] as $iban) {
            $debtor = Debtor::factory()->create([
                'upload_id' => $uploadA->id,
                'iban' => $iban,
                'tether_instance_id' => $instanceA->id,
            ]);
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_APPROVED,
            ]);
        }

        $rows = [
            ['iban' => 'IBAN_1', 'name' => 'User1'],
            ['iban' => 'IBAN_2', 'name' => 'User2'],
            ['iban' => 'IBAN_3', 'name' => 'User3'],
        ];

        $result = FileUploadService::filterCrossAccountIbans(
            $rows,
            $instanceB->id,
            ['iban' => 'iban']
        );

        $this->assertCount(1, $result['rows']);
        $this->assertEquals(2, $result['excluded_count']);
        $this->assertCount(2, $result['errors']);
        $this->assertEquals('IBAN_3', $result['rows'][0]['iban']);
    }

    public function test_filter_cross_account_ibans_ignores_non_approved_billing(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $instanceB = $this->createInstanceWithAccount($accountB);

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $uploadA->id,
            'iban' => 'IBAN_PENDING',
            'tether_instance_id' => $instanceA->id,
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $rows = [
            ['iban' => 'IBAN_PENDING', 'name' => 'User1'],
        ];

        $result = FileUploadService::filterCrossAccountIbans(
            $rows,
            $instanceB->id,
            ['iban' => 'iban']
        );

        $this->assertCount(1, $result['rows'], 'Non-approved billing should not lock the IBAN');
        $this->assertEquals(0, $result['excluded_count']);
    }

    public function test_process_passes_is_30d_cool_flag(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, is30dCool: true);

        $this->assertTrue($result['upload']->is_30d_cool);
    }

    public function test_process_passes_skip_chargeback_check_flag(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file, skipChargebackCheck: true);

        $this->assertTrue($result['upload']->skip_chargeback_check);
    }

    public function test_process_returns_expected_keys(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $this->assertArrayHasKey('upload', $result);
        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertInstanceOf(Upload::class, $result['upload']);
    }

    public function test_process_async_returns_expected_keys(): void
    {
        Queue::fake();

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->processAsync($file);

        $this->assertArrayHasKey('upload', $result);
        $this->assertArrayHasKey('queued', $result);
        $this->assertInstanceOf(Upload::class, $result['upload']);
        $this->assertTrue($result['queued']);
    }

    public function test_process_stores_errors_in_upload_meta(): void
    {
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('DE89370400440532013000', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\nJohn,Doe,DE89370400440532013000,100.00";
        $file = UploadedFile::fake()->createWithContent('test.csv', $content);

        $result = $service->process($file);

        $upload = $result['upload'];
        $this->assertArrayHasKey('errors', $upload->meta);
        $this->assertArrayHasKey('skipped', $upload->meta);
    }

    public function test_process_handles_csv_with_only_headers(): void
    {
        $service = $this->createService();

        $content = "first_name,last_name,iban,amount";
        $file = UploadedFile::fake()->createWithContent('empty.csv', $content);

        $result = $service->process($file);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['upload']->total_records);
    }

    public function test_global_lock_errors_merged_with_import_errors(): void
    {
        $accountA = EmpAccount::factory()->create();
        $accountB = EmpAccount::factory()->create();
        $instanceA = $this->createInstanceWithAccount($accountA);
        $instanceB = $this->createInstanceWithAccount($accountB);

        $uploadA = Upload::factory()->create([
            'emp_account_id' => $accountA->id,
            'tether_instance_id' => $instanceA->id,
        ]);
        $debtor = Debtor::factory()->create([
            'upload_id' => $uploadA->id,
            'iban' => 'LOCKED_IBAN',
            'tether_instance_id' => $instanceA->id,
        ]);
        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        // Add a blacklisted IBAN too
        $ibanValidator = new IbanValidator();
        $blacklistService = new BlacklistService($ibanValidator);
        $blacklistService->add('BLACKLISTED_IBAN', 'Fraud');

        $service = $this->createService();

        $content = "first_name,last_name,iban,amount\n"
            . "Locked,User,LOCKED_IBAN,100.00\n"
            . "Blocked,User,BLACKLISTED_IBAN,200.00\n"
            . "Good,User,DE89370400440532013000,300.00";
        $file = UploadedFile::fake()->createWithContent('mixed_errors.csv', $content);

        $result = $service->process(
            file: $file,
            empAccountId: $accountB->id,
            applyGlobalLock: true,
            tetherInstanceId: $instanceB->id
        );

        // One created (Good User), one locked, one blacklisted
        $this->assertEquals(1, $result['created']);
        $this->assertGreaterThanOrEqual(1, $result['skipped']['total']);
        // Both lock errors and import errors should be in the errors array
        $this->assertNotEmpty($result['errors']);
    }
}
