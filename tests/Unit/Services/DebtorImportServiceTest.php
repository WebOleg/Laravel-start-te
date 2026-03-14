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
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Recovery->value]);

        $rows = [['First' => 'Small', 'Last' => 'Bill', 'IBAN' => 'DE_SMALL', 'Amount' => '10.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_SMALL');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(1, $result['created'], 'Row should be created, not skipped');

        $debtor = Debtor::where('iban', 'DE_SMALL')->first();

        $this->assertNotEquals(BillingModel::Recovery->value, $debtor->billing_model);
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

    public function test_import_with_is_30d_cool_true_excludes_recently_attempted(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => true,
        ]);

        $rows = [
            ['First' => 'Recent', 'Last' => 'Attempt', 'IBAN' => 'DE_RECENT', 'Amount' => '100.00'],
        ];

        $columnMapping = [
            'First' => 'first_name',
            'Last' => 'last_name',
            'IBAN' => 'iban',
            'Amount' => 'amount'
        ];

        $this->mockIbanValidation('DE_RECENT');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_RECENTLY_ATTEMPTED, 'days_ago' => 10, 'permanent' => false]
            ]);

        $result = $this->service->importRows($upload, $rows, $columnMapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertDatabaseCount('debtors', 0);
    }

    public function test_import_with_is_30d_cool_false_includes_recently_attempted(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => false,
        ]);

        $rows = [
            ['First' => 'Recent', 'Last' => 'Attempt', 'IBAN' => 'DE_RECENT', 'Amount' => '100.00'],
        ];

        $columnMapping = [
            'First' => 'first_name',
            'Last' => 'last_name',
            'IBAN' => 'iban',
            'Amount' => 'amount'
        ];

        $this->mockIbanValidation('DE_RECENT');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_RECENTLY_ATTEMPTED, 'days_ago' => 10, 'permanent' => false]
            ]);

        $result = $this->service->importRows($upload, $rows, $columnMapping);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['skipped']['total']);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'iban' => 'DE_RECENT',
            'first_name' => 'Recent',
            'last_name' => 'Attempt',
        ]);
    }

    public function test_import_with_is_30d_cool_true_still_excludes_permanent_skips(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => false,
        ]);

        $rows = [
            ['First' => 'Blacklist', 'Last' => 'Test', 'IBAN' => 'DE_BLACKLIST', 'Amount' => '100.00'],
        ];

        $columnMapping = [
            'First' => 'first_name',
            'Last' => 'last_name',
            'IBAN' => 'iban',
            'Amount' => 'amount'
        ];

        $this->mockIbanValidation('DE_BLACKLIST');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_BLACKLISTED, 'permanent' => true]
            ]);

        $result = $this->service->importRows($upload, $rows, $columnMapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_BLACKLISTED]);
        $this->assertDatabaseCount('debtors', 0);
    }

    public function test_import_with_is_30d_cool_false_excludes_chargebacked(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'is_30d_cool' => false,
        ]);

        $rows = [
            ['First' => 'Chargebacked', 'Last' => 'Debtor', 'IBAN' => 'DE_CHARGEBACK', 'Amount' => '100.00'],
        ];

        $columnMapping = [
            'First' => 'first_name',
            'Last' => 'last_name',
            'IBAN' => 'iban',
            'Amount' => 'amount'
        ];

        $this->mockIbanValidation('DE_CHARGEBACK');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_CHARGEBACKED, 'permanent' => true]
            ]);

        $result = $this->service->importRows($upload, $rows, $columnMapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_CHARGEBACKED]);
    }

    public function test_import_rows_returns_expected_keys(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, [], []);

        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('failed', $result);
        $this->assertArrayHasKey('skipped', $result);
        $this->assertArrayHasKey('skipped_rows', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_import_rows_with_empty_rows_returns_zero_counts(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, [], []);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']['total']);
        $this->assertEmpty($result['skipped_rows']);
        $this->assertEmpty($result['errors']);
    }

    public function test_import_rows_handles_mix_of_created_and_skipped(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'Good', 'Last' => 'User', 'IBAN' => 'DE_GOOD', 'Amount' => '100.00'],
            ['First' => 'Bad', 'Last' => 'User', 'IBAN' => 'DE_BAD', 'Amount' => '200.00'],
            ['First' => 'Also', 'Last' => 'Good', 'IBAN' => 'DE_ALSO', 'Amount' => '300.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_GOOD');
        $this->mockIbanValidation('DE_BAD');
        $this->mockIbanValidation('DE_ALSO');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                1 => ['reason' => DeduplicationService::SKIP_BLACKLISTED],
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(2, $result['created']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertDatabaseCount('debtors', 2);
    }

    public function test_import_rows_handles_empty_iban(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'No', 'Last' => 'Iban', 'IBAN' => '', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        // Empty IBAN should still trigger normalize with empty string
        $this->ibanValidator->shouldReceive('normalize')->andReturnUsing(fn ($v) => $v);
        $this->ibanValidator->shouldReceive('hash')->andReturn(null);
        $this->ibanValidator->shouldReceive('validate')->andReturn(['valid' => false, 'bank_id' => null]);
        $this->ibanValidator->shouldReceive('mask')->andReturn('****');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        // Should still create the debtor (with empty IBAN) — validation happens later
        $this->assertEquals(1, $result['created']);
        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'first_name' => 'No',
            'last_name' => 'Iban',
        ]);
    }

    public function test_import_rows_default_start_row_is_one(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['IBAN' => 'DE_FAIL', 'Amount' => 'bad'],
        ];
        $mapping = ['IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_FAIL');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, $rows, $mapping, true);

        $this->assertEquals(1, $result['failed']);
        // Default startRow=1: row number = 1 + 0 + 1 = 2
        $this->assertEquals(2, $result['errors'][0]['row']);
    }

    public function test_import_rows_sets_validation_status_to_pending(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);
    }

    public function test_import_rows_stores_original_row_as_raw_data(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00', 'Extra' => 'custom_val'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $debtor = Debtor::where('upload_id', $upload->id)->first();
        $this->assertEquals('custom_val', $debtor->raw_data['Extra']);
        $this->assertEquals('John', $debtor->raw_data['First']);
    }

    public function test_import_rows_reuses_existing_profile_and_sets_profile_id(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $existingProfile = DebtorProfile::create([
            'iban_hash' => 'hash_DE123456',
            'billing_model' => BillingModel::Legacy->value,
            'is_active' => true,
        ]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $debtor = Debtor::where('upload_id', $upload->id)->first();
        $this->assertEquals($existingProfile->id, $debtor->debtor_profile_id);

        // Should not create a duplicate profile
        $this->assertDatabaseCount('debtor_profiles', 1);
    }

    public function test_import_with_skip_chargeback_check_bypasses_chargebacked(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'skip_chargeback_check' => true,
        ]);

        $rows = [
            ['First' => 'CB', 'Last' => 'User', 'IBAN' => 'DE_CB', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_CB');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_CHARGEBACKED, 'permanent' => true],
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(0, $result['skipped']['total']);
    }

    public function test_import_without_skip_chargeback_check_blocks_chargebacked(): void
    {
        $upload = Upload::factory()->create([
            'billing_model' => BillingModel::Legacy->value,
            'skip_chargeback_check' => false,
        ]);

        $rows = [
            ['First' => 'CB', 'Last' => 'User', 'IBAN' => 'DE_CB', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_CB');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->once()
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_CHARGEBACKED, 'permanent' => true],
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_CHARGEBACKED]);
    }

    public function test_autoswitch_meta_includes_upload_and_row_model(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Recovery->value]);

        $rows = [['First' => 'Small', 'Last' => 'Bill', 'IBAN' => 'DE_SMALL', 'Amount' => '10.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_SMALL');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $debtor = Debtor::where('iban', 'DE_SMALL')->first();

        $this->assertEquals(BillingModel::Recovery->value, $debtor->meta['upload_billing_model']);
        $this->assertNotEquals(BillingModel::Recovery->value, $debtor->meta['row_billing_model']);
        $this->assertEquals(10.00, $debtor->meta['row_amount']);
    }

    public function test_no_autoswitch_meta_when_models_match(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $debtor = Debtor::where('iban', 'DE123456')->first();

        // No autoswitch should have occurred for Legacy
        $this->assertNull($debtor->meta['row_model_autoswitched'] ?? null);
    }

    public function test_import_rows_defaults_currency_to_eur(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'currency' => 'EUR',
        ]);
    }

    public function test_import_rows_sets_status_to_uploaded(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE123456', 'Amount' => '100.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE123456');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $this->assertDatabaseHas('debtors', [
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_UPLOADED,
        ]);
    }

    public function test_import_rows_caps_errors_at_100(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        // Generate 110 rows that will all fail validation
        $rows = [];
        $mapping = ['IBAN' => 'iban', 'Amount' => 'amount'];
        for ($i = 0; $i < 110; $i++) {
            $rows[] = ['IBAN' => "DE_FAIL_{$i}", 'Amount' => 'invalid'];
            $this->mockIbanValidation("DE_FAIL_{$i}");
        }

        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $result = $this->service->importRows($upload, $rows, $mapping, true);

        $this->assertEquals(110, $result['failed']);
        // Errors array should be capped at 100
        $this->assertCount(100, $result['errors']);
    }

    public function test_import_rows_tracks_multiple_skip_reasons(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'BL', 'Last' => 'User', 'IBAN' => 'DE_BL', 'Amount' => '100'],
            ['First' => 'CB', 'Last' => 'User', 'IBAN' => 'DE_CB', 'Amount' => '200'],
            ['First' => 'OK', 'Last' => 'User', 'IBAN' => 'DE_OK', 'Amount' => '300'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_BL');
        $this->mockIbanValidation('DE_CB');
        $this->mockIbanValidation('DE_OK');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_BLACKLISTED],
                1 => ['reason' => DeduplicationService::SKIP_CHARGEBACKED],
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(1, $result['created']);
        $this->assertEquals(2, $result['skipped']['total']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_BLACKLISTED]);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_CHARGEBACKED]);
    }

    public function test_skipped_rows_include_masked_iban_and_name(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'Bad', 'Last' => 'Actor', 'IBAN' => 'DE_BAD', 'Amount' => '50'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_BAD');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')
            ->andReturn([
                0 => ['reason' => DeduplicationService::SKIP_BLACKLISTED],
            ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertCount(1, $result['skipped_rows']);
        $skippedRow = $result['skipped_rows'][0];
        $this->assertEquals('****_BAD', $skippedRow['iban_masked']);
        $this->assertEquals('Bad Actor', $skippedRow['name']);
        $this->assertEquals(DeduplicationService::SKIP_BLACKLISTED, $skippedRow['reason']);
    }

    public function test_import_rows_with_duplicate_ibans_reuses_profile(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [
            ['First' => 'John', 'Last' => 'Doe', 'IBAN' => 'DE_SAME', 'Amount' => '100.00'],
            ['First' => 'Jane', 'Last' => 'Doe', 'IBAN' => 'DE_SAME', 'Amount' => '200.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_SAME');

        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        // Both debtors should share the same profile
        $this->assertDatabaseCount('debtor_profiles', 1);
        $this->assertDatabaseCount('debtors', 2);

        $debtors = Debtor::where('upload_id', $upload->id)->get();
        $this->assertEquals(
            $debtors[0]->debtor_profile_id,
            $debtors[1]->debtor_profile_id
        );
    }

    public function test_legacy_upload_always_resolves_to_legacy_model(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        // Even a high amount stays Legacy when upload is Legacy
        $rows = [
            ['First' => 'High', 'Last' => 'Amount', 'IBAN' => 'DE_HIGH', 'Amount' => '5000.00'],
        ];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_HIGH');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        $this->service->importRows($upload, $rows, $mapping);

        $debtor = Debtor::where('iban', 'DE_HIGH')->first();
        $this->assertEquals(BillingModel::Legacy->value, $debtor->billing_model);
        $this->assertNull($debtor->meta['row_model_autoswitched'] ?? null);
    }

    public function test_finalize_upload_completed_when_created_and_failed_both_present(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $result = [
            'created' => 3,
            'failed' => 2,
            'skipped' => ['total' => 0],
            'skipped_rows' => [],
            'errors' => [['message' => 'err1'], ['message' => 'err2']],
        ];

        $this->service->finalizeUpload($upload, $result);

        $upload->refresh();
        // Some created > 0, so status should be COMPLETED even with failures
        $this->assertEquals(Upload::STATUS_COMPLETED, $upload->status);
        $this->assertEquals(3, $upload->processed_records);
        $this->assertEquals(2, $upload->failed_records);
    }

    public function test_finalize_upload_preserves_existing_meta(): void
    {
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_PROCESSING,
            'meta' => ['original_key' => 'original_value', 'apply_global_lock' => true],
        ]);

        $result = [
            'created' => 1,
            'failed' => 0,
            'skipped' => ['total' => 0],
            'skipped_rows' => [],
            'errors' => [],
        ];

        $this->service->finalizeUpload($upload, $result);

        $upload->refresh();
        $this->assertEquals('original_value', $upload->meta['original_key']);
        $this->assertTrue($upload->meta['apply_global_lock']);
        $this->assertArrayHasKey('skipped', $upload->meta);
    }

    public function test_finalize_upload_truncates_errors_at_100(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $errors = [];
        for ($i = 0; $i < 150; $i++) {
            $errors[] = ['message' => "Error {$i}"];
        }

        $result = [
            'created' => 0,
            'failed' => 150,
            'skipped' => [],
            'skipped_rows' => [],
            'errors' => $errors,
        ];

        $this->service->finalizeUpload($upload, $result);

        $upload->refresh();
        $this->assertCount(100, $upload->meta['errors']);
    }

    public function test_finalize_upload_truncates_skipped_rows_at_100(): void
    {
        $upload = Upload::factory()->create(['status' => Upload::STATUS_PROCESSING]);

        $skippedRows = [];
        for ($i = 0; $i < 150; $i++) {
            $skippedRows[] = ['row' => $i, 'reason' => 'test'];
        }

        $result = [
            'created' => 0,
            'failed' => 0,
            'skipped' => ['total' => 150],
            'skipped_rows' => $skippedRows,
            'errors' => [],
        ];

        $this->service->finalizeUpload($upload, $result);

        $upload->refresh();
        $this->assertCount(100, $upload->meta['skipped_rows']);
    }

    public function test_skips_conflict_legacy_row_vs_flywheel_profile(): void
    {
        $upload = Upload::factory()->create(['billing_model' => BillingModel::Legacy->value]);

        $rows = [['First' => 'Legacy', 'Last' => 'Row', 'IBAN' => 'DE_FW_PROFILE', 'Amount' => '100.00']];
        $mapping = ['First' => 'first_name', 'Last' => 'last_name', 'IBAN' => 'iban', 'Amount' => 'amount'];

        $this->mockIbanValidation('DE_FW_PROFILE');
        $this->deduplicationService->shouldReceive('checkDebtorBatch')->andReturn([]);

        DebtorProfile::create([
            'iban_hash' => 'hash_DE_FW_PROFILE',
            'billing_model' => BillingModel::Flywheel->value,
            'is_active' => true,
        ]);

        $result = $this->service->importRows($upload, $rows, $mapping);

        $this->assertEquals(0, $result['created']);
        $this->assertEquals(1, $result['skipped'][DeduplicationService::SKIP_MODEL_CONFLICT]);
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
