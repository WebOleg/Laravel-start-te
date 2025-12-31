<?php

namespace Tests\Unit\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Services\Emp\EmpBillingService;
use App\Services\Emp\EmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmpBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmpBillingService $service;
    private $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = Mockery::mock(EmpClient::class);
        $this->service = new EmpBillingService($this->mockClient);
    }

    public function test_bill_debtor_creates_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 99.99,
            'iban' => 'DE89370400440532013000',
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'emp_unique_123',
                'message' => 'Transaction successful',
            ]);

        $result = $this->service->billDebtor($debtor);

        $this->assertInstanceOf(BillingAttempt::class, $result);
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $result->status);
        $this->assertEquals('emp_unique_123', $result->unique_id);
        $this->assertEquals(99.99, $result->amount);
        $this->assertEquals($debtor->id, $result->debtor_id);
    }

    public function test_bill_debtor_handles_pending_async(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 50.00,
            'iban' => 'FR7630006000011234567890189',
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'pending_async',
                'unique_id' => 'emp_pending_456',
                'redirect_url' => 'https://example.com/redirect',
            ]);

        $result = $this->service->billDebtor($debtor);

        $this->assertEquals(BillingAttempt::STATUS_PENDING, $result->status);
        $this->assertEquals('https://example.com/redirect', $result->meta['redirect_url']);
    }

    public function test_bill_debtor_handles_declined(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 100.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'declined',
                'unique_id' => 'emp_declined_789',
                'code' => '340',
                'technical_message' => 'Insufficient funds',
            ]);

        $result = $this->service->billDebtor($debtor);

        $this->assertEquals(BillingAttempt::STATUS_DECLINED, $result->status);
        $this->assertEquals('340', $result->error_code);
    }

    public function test_cannot_bill_invalid_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'invalid',
            'status' => Debtor::STATUS_PENDING,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->billDebtor($debtor);
    }

    public function test_cannot_bill_debtor_with_pending_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'iban' => 'DE89370400440532013000',
            'amount' => 50.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $this->assertFalse($this->service->canBill($debtor));
    }

    public function test_cannot_bill_already_approved_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_RECOVERED,
            'iban' => 'DE89370400440532013000',
            'amount' => 50.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
        ]);

        $this->assertFalse($this->service->canBill($debtor));
    }

    public function test_bill_batch_processes_multiple_debtors(): void
    {
        $upload = Upload::factory()->create();
        $debtors = Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 25.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->times(3)
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'batch_' . uniqid(),
            ]);

        $result = $this->service->billBatch($debtors);

        $this->assertEquals(3, $result['success']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertCount(3, $result['attempts']);
    }

    public function test_bill_batch_skips_invalid_debtors(): void
    {
        $upload = Upload::factory()->create();
        
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 25.00,
            'iban' => 'DE89370400440532013000',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'invalid',
            'status' => Debtor::STATUS_PENDING,
        ]);

        $debtors = Debtor::where('upload_id', $upload->id)->get();

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'valid_123',
            ]);

        $result = $this->service->billBatch($debtors);

        $this->assertEquals(1, $result['success']);
        $this->assertEquals(1, $result['skipped']);
    }

    public function test_retry_creates_new_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'amount' => 75.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $failedAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'attempt_number' => 1,
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'retry_success',
            ]);

        $result = $this->service->retry($failedAttempt);

        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $result->status);
        $this->assertEquals(2, $result->attempt_number);
    }

    public function test_reconcile_updates_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);
        
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'reconcile_123',
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $this->mockClient->shouldReceive('reconcile')
            ->with('reconcile_123')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'reconcile_123',
            ]);

        $result = $this->service->reconcile($billingAttempt);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_cannot_bill_amount_below_one_euro(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'iban' => 'DE89370400440532013000',
            'amount' => 0.50,
        ]);

        $this->assertFalse($this->service->canBill($debtor));
    }

    public function test_can_bill_amount_exactly_one_euro(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_PENDING,
            'iban' => 'DE89370400440532013000',
            'amount' => 1.00,
        ]);

        $this->assertTrue($this->service->canBill($debtor));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
