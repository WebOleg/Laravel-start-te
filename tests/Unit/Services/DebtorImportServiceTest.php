<?php

namespace Tests\Unit\Services;

use App\Enums\BillingModel;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\Upload;
use App\Services\DebtorImportService;
use App\Services\DeduplicationService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DebtorImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private DebtorImportService $service;
    private $ibanValidator;
    private $deduplicationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ibanValidator = Mockery::mock(IbanValidator::class);
        $this->deduplicationService = Mockery::mock(DeduplicationService::class);

        $this->service = new DebtorImportService(
            $this->ibanValidator,
            $this->deduplicationService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_import_rows_creates_debtors_and_profiles(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00'],
        ];

        $columnMapping = [
            'First' => 'first_name',
            'Last' => 'last_name',
            'IBAN' => 'iban',
            'Amount' => 'amount'
        ];

        $this->mockIbanValidation('DE123456');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([]);

        $result = $this->service->importRows($upload, $rows, $columnMapping);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['failed']);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'iban' => 'DE123456',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'amount' => 100.00,
            'billing_model' => BillingModel::Legacy->value,
        ]);

        $this->assertDatabaseHas('debtor_profiles', [
            'iban_hash' => 'hash_DE123456',
            'billing_model' => BillingModel::Legacy->value,
        ]);
    }

    public function test_import_skips_blacklisted_rows(): void
    {
        $upload = Upload::factory()->create();
        $rows = [['First' => 'Bad', 'Last' => 'Actor', 'IBAN' => 'DE_BAD', 'Amount' => '50']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_BAD');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_BLACKLISTED]
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_BLACKLISTED]);
        $this->assertDatabaseCount('debtors', 0);
    }

    public function test_autoswitch_billing_model_based_on_amount(): void
    {
        // Setup: Upload is Recovery (High value), but Row is 10.00 (Low value)
        // We expect the system to autoswitch to Flywheel (or Legacy)
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Recovery->value]);

        $rows = [['First' => 'Small', 'Last' => 'Bill', 'IBAN' => 'DE_SMALL', 'Amount' => '10.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_SMALL');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        // FIX: Do NOT create a profile beforehand.
        // This ensures the row creates its own profile based on the resolved model,
        // avoiding "Model Conflict" skips.

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(1, $result['created'], 'Row should be created, not skipped');

        $debtor = Debtor::where('iban', 'DE_SMALL')->first();

        // Assert autoswitch occurred (Debtor model should NOT match Upload model)
        $this->assertNotEquals(BillingModel::Recovery->value, $debtor->billing_model);

        // Assert the meta flag was set
        $this->assertTrue($debtor->meta['row_model_autoswitched'] ?? false);
    }

    public function test_skips_conflict_existing_legacy_vs_incoming_flywheel(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Flywheel->value]);

        $rows = [['First' => 'Conflict', 'Last' => 'Legacy', 'IBAN' => 'DE_LEGACY', 'Amount' => '4']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_LEGACY');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        DebtorProfile::create([
            'iban_hash' => 'hash_DE_LEGACY',
            'billing_model' => BillingModel::Legacy->value,
            'is_active' => true,
        ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_EXISTING_LEGACY_IBAN]);
    }

    public function test_skips_conflict_flywheel_vs_recovery(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Recovery->value]);

        $rows = [['First' => 'Conflict', 'Last' => 'Flywheel', 'IBAN' => 'DE_FLYWHEEL', 'Amount' => '100.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_FLYWHEEL');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        DebtorProfile::create([
            'iban_hash' => 'hash_DE_FLYWHEEL',
            'billing_model' => BillingModel::Flywheel->value,
            'is_active' => true,
        ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_MODEL_CONFLICT]);
    }

    public function test_finalize_upload_updates_status_and_meta(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $result = [
            'created' => 5,
            'failed' => 0,
            'skipped' => ['total' => 2],
            'skipped_rows' => [['row' => 1, 'reason' => 'test']],
            'errors' => []
        ];

        $this->service->finalizeUpload($upload, $result);

        $upload->refresh();

        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(5, $upload->processed_records);
        $this->assertEquals(0, $upload->failed_records);
        $this->assertNotNull($upload->processing_completed_at);
        $this->assertEquals(2, $upload->meta['skipped']['total']);
    }

    public function test_finalize_upload_marks_failed_if_zero_processed(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $result = [
            'created' => 0,
            'failed' => 1,
            'skipped' => [],
            'skipped_rows' => [],
            'errors' => [['message' => 'Error']]
        ];

        $this->service->finalizeUpload($upload, $result);

        $this->assertEquals(Upload::STATUS_FAILED, $upload->fresh()->status);
    }

    /**
     * Helper to mock common IbanValidator calls
     */
    private function mockIbanValidation(string $iban): void
    {
        $this->ibanValidator->shouldReceive('normalize')->with($iban)->andReturn($iban);
        $this->ibanValidator->shouldReceive('hash')->with($iban)->andReturn('hash_' . $iban);
        $this->ibanValidator->shouldReceive('validate')->with($iban)->andReturn([
            'valid' => true,
            'bank_id' => 'BANK123'
        ]);
        $this->ibanValidator->shouldReceive('mask')->with($iban)->andReturn('****' . substr($iban, -4));
    }
}
