<?php

namespace Tests\Unit\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\BlacklistService;
use App\Services\ChargebackService;
use App\Services\Emp\EmpChargebackSyncService;
use App\Services\Emp\EmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmpChargebackSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmpChargebackSyncService $service;
    private $mockClient;
    private $mockBlacklistService;
    private $mockChargebackService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = Mockery::mock(EmpClient::class);
        $this->mockBlacklistService = Mockery::mock(BlacklistService::class);
        $this->mockChargebackService = Mockery::mock(ChargebackService::class);
        $this->mockChargebackService->shouldReceive('createFromApiSync')->andReturnNull();
        
        $this->service = new EmpChargebackSyncService(
            $this->mockClient,
            $this->mockBlacklistService,
            $this->mockChargebackService
        );
    }

    public function test_sync_processes_chargeback_and_updates_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PROCESSING,
        ]);
        
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_123',
            'unique_id' => 'emp_unique_abc123',
            'amount' => 100.00,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->with('2026-01-22', 1, 100)
            ->once()
            ->andReturn([
                '@page' => '1',
                '@pages_count' => '1',
                '@total_count' => '1',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'emp_unique_abc123',
                    'reason_code' => 'MD06',
                    'reason_description' => 'Refund request by customer',
                    'post_date' => '2026-01-22',
                    'type' => '1st chargeback',
                    'chargeback_amount' => '100.00',
                    'chargeback_currency' => 'EUR',
                ],
            ]);

        $stats = $this->service->syncByDate('2026-01-22');

        $this->assertEquals(1, $stats['total_fetched']);
        $this->assertEquals(1, $stats['matched']);
        $this->assertEquals(0, $stats['already_processed']);
        $this->assertEquals(0, $stats['unmatched']);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_CHARGEBACKED, $billingAttempt->status);
        $this->assertEquals('MD06', $billingAttempt->chargeback_reason_code);
        $this->assertNotNull($billingAttempt->chargebacked_at);

        $debtor->refresh();
        $this->assertEquals(Debtor::STATUS_CHARGEBACKED, $debtor->status);
    }

    public function test_sync_skips_already_processed_chargeback(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_CHARGEBACKED,
        ]);
        
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_456',
            'unique_id' => 'emp_unique_already',
            'amount' => 50.00,
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
        ]);

        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->once()
            ->andReturn([
                '@pages_count' => '1',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'emp_unique_already',
                    'reason_code' => 'AC04',
                ],
            ]);

        $stats = $this->service->syncByDate('2026-01-22');

        $this->assertEquals(1, $stats['total_fetched']);
        $this->assertEquals(0, $stats['matched']);
        $this->assertEquals(1, $stats['already_processed']);
    }

    public function test_sync_tracks_unmatched_chargebacks(): void
    {
        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->once()
            ->andReturn([
                '@pages_count' => '1',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'unknown_tx_999',
                    'reason_code' => 'MD01',
                ],
            ]);

        $stats = $this->service->syncByDate('2026-01-22');

        $this->assertEquals(1, $stats['unmatched']);
        $this->assertEquals(0, $stats['matched']);
    }

    public function test_sync_blacklists_debtor_for_auto_blacklist_codes(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PROCESSING,
        ]);
        
        BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_789',
            'unique_id' => 'emp_blacklist_test',
            'amount' => 75.00,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->once()
            ->andReturn([
                '@pages_count' => '1',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'emp_blacklist_test',
                    'reason_code' => 'AC04',
                    'reason_description' => 'Account closed',
                    'post_date' => '2026-01-22',
                ],
            ]);

        $this->mockBlacklistService
            ->shouldReceive('addDebtor')
            ->once()
            ->with(
                Mockery::type(Debtor::class),
                'Chargeback: AC04 - Account closed',
                'chargeback_sync'
            );

        $stats = $this->service->syncByDate('2026-01-22');

        $this->assertEquals(1, $stats['matched']);
        $this->assertEquals(1, $stats['blacklisted']);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_PROCESSING,
        ]);
        
        $billingAttempt = BillingAttempt::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'transaction_id' => 'tx_dry',
            'unique_id' => 'emp_dry_run_test',
            'amount' => 100.00,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->once()
            ->andReturn([
                '@pages_count' => '1',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'emp_dry_run_test',
                    'reason_code' => 'MD06',
                ],
            ]);

        $stats = $this->service->syncByDate('2026-01-22', dryRun: true);

        $this->assertEquals(1, $stats['matched']);
        $this->assertTrue($stats['dry_run']);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_sync_handles_pagination(): void
    {
        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->with('2026-01-22', 1, 100)
            ->once()
            ->andReturn([
                '@page' => '1',
                '@pages_count' => '2',
                '@total_count' => '2',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'page1_tx',
                    'reason_code' => 'MD06',
                ],
            ]);

        $this->mockClient
            ->shouldReceive('getChargebacksByImportDate')
            ->with('2026-01-22', 2, 100)
            ->once()
            ->andReturn([
                '@page' => '2',
                '@pages_count' => '2',
                'chargeback_response' => [
                    'original_transaction_unique_id' => 'page2_tx',
                    'reason_code' => 'AC04',
                ],
            ]);

        $stats = $this->service->syncByDate('2026-01-22');

        $this->assertEquals(2, $stats['total_fetched']);
        $this->assertEquals(2, $stats['pages_processed']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
