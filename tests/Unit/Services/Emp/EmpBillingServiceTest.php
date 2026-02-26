<?php

namespace Tests\Unit\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\EmpAccount;
use App\Models\TetherInstance;
use App\Models\TransactionDescriptor;
use App\Models\Upload;
use App\Services\DescriptorService;
use App\Services\Emp\EmpBillingService;
use App\Services\Emp\EmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmpBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmpBillingService $service;
    private mixed $mockClient;
    private mixed $mockDescriptorService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Mock Client
        $this->mockClient = Mockery::mock(EmpClient::class);
        $this->mockClient->shouldReceive('getTerminalToken')
            ->andReturn('test_terminal_token_123');
        $this->mockClient->shouldReceive('getEmpAccountId')
            ->andReturn(null);

        // 2. Mock Descriptor Service
        $this->mockDescriptorService = Mockery::mock(DescriptorService::class);

        // Handle the call made inside buildRequestPayload to prevent errors
        $this->mockDescriptorService->shouldReceive('getActiveDescriptor')
            ->byDefault()
            ->andReturn(null);

        // 3. Instantiate Service with both dependencies
        $this->service = new EmpBillingService($this->mockClient, $this->mockDescriptorService);
    }

    public function test_bill_debtor_creates_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
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
        $this->assertEquals('test_terminal_token_123', $result->mid_reference);
    }

    public function test_bill_debtor_handles_pending_async(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
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
            'status' => Debtor::STATUS_UPLOADED,
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
            'status' => Debtor::STATUS_UPLOADED,
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
            'status' => Debtor::STATUS_UPLOADED,
            'iban' => 'DE89370400440532013000',
            'amount' => 50.00,
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_PENDING,
            'unique_id' => 'pending_attempt_' . uniqid(),
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
            'unique_id' => 'approved_attempt_' . uniqid(),
        ]);

        $this->assertFalse($this->service->canBill($debtor));
    }

    public function test_bill_batch_processes_multiple_debtors(): void
    {
        $upload = Upload::factory()->create();
        $debtors = Debtor::factory()->count(3)->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 25.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $callCount = 0;
        $this->mockClient->shouldReceive('sddSale')
            ->times(3)
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return [
                    'status' => 'approved',
                    'unique_id' => 'batch_' . $callCount . '_' . uniqid(),
                ];
            });

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
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 25.00,
            'iban' => 'DE89370400440532013000',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'invalid',
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $debtors = Debtor::where('upload_id', $upload->id)->get();

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'valid_123_' . uniqid(),
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
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 75.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $failedAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_DECLINED,
            'attempt_number' => 1,
            'unique_id' => 'failed_attempt_' . uniqid(),
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'retry_success_' . uniqid(),
            ]);

        $result = $this->service->retry($failedAttempt);

        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $result->status);
        $this->assertEquals(2, $result->attempt_number);
    }

    public function test_reconcile_updates_billing_attempt(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $uniqueId = 'reconcile_' . uniqid();
        $billingAttempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => $uniqueId,
            'status' => BillingAttempt::STATUS_PENDING,
        ]);

        $this->mockClient->shouldReceive('reconcile')
            ->with($uniqueId)
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => $uniqueId,
            ]);

        $result = $this->service->reconcile($billingAttempt);

        $billingAttempt->refresh();
        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $billingAttempt->status);
    }

    public function test_can_bill_amount_exactly_one_euro(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
            'iban' => 'DE89370400440532013000',
            'amount' => 1.00,
        ]);

        $this->assertTrue($this->service->canBill($debtor));
    }

    public function test_bill_upload_processes_valid_debtors_only(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 50.00,
            'iban' => 'DE89370400440532013000',
        ]);

        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'invalid',
            'status' => Debtor::STATUS_UPLOADED,
        ]);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'upload_batch_' . uniqid(),
            ]);

        $result = $this->service->billUpload($upload);

        $this->assertEquals(1, $result['success']);
    }

    public function test_dynamic_descriptor_is_injected_into_payload(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 45.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $mockDescriptor = new TransactionDescriptor();
        $mockDescriptor->descriptor_name = 'TETHER_SAAS';
        $mockDescriptor->descriptor_city = 'London';
        $mockDescriptor->descriptor_country = 'UK';

        $this->mockDescriptorService->shouldReceive('getActiveDescriptor')
            ->once()
            ->andReturn($mockDescriptor);

        $this->mockClient->shouldReceive('sddSale')
            ->withArgs(function ($payload) {
                return isset($payload['dynamic_descriptor_params']) &&
                    $payload['dynamic_descriptor_params']['merchant_name'] === 'TETHER_SAAS' &&
                    $payload['dynamic_descriptor_params']['merchant_city'] === 'London';
            })
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'emp_desc_123',
            ]);

        $result = $this->service->billDebtor($debtor);

        $this->assertInstanceOf(BillingAttempt::class, $result);
        $this->assertArrayHasKey('dynamic_descriptor_params', $result->request_payload);
        $this->assertEquals('TETHER_SAAS', $result->request_payload['dynamic_descriptor_params']['merchant_name']);
        $this->assertEquals('London', $result->request_payload['dynamic_descriptor_params']['merchant_city']);
        $this->assertEquals('UK', $result->request_payload['dynamic_descriptor_params']['merchant_country']);
    }

    public function test_void_attempt_success(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_APPROVED,
        ]);

        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'original_tx_123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'emp_account_id' => null,
        ]);

        $this->mockClient->shouldReceive('voidTransaction')
            ->with('original_tx_123', Mockery::type('string'), 'original_tx_123')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'void_response_456',
            ]);

        $result = $this->service->voidAttempt($attempt);

        $this->assertTrue($result);

        $attempt->refresh();
        $debtor->refresh();

        $this->assertEquals(BillingAttempt::STATUS_VOIDED, $attempt->status);
        $this->assertEquals('void_response_456', $attempt->meta['void_unique_id']);
        $this->assertEquals(Debtor::STATUS_UPLOADED, $debtor->status);
    }

    public function test_void_attempt_failure(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'status' => Debtor::STATUS_APPROVED,
        ]);

        $attempt = BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'unique_id' => 'original_tx_123',
            'status' => BillingAttempt::STATUS_APPROVED,
            'emp_account_id' => null,
        ]);

        $this->mockClient->shouldReceive('voidTransaction')
            ->with('original_tx_123', Mockery::type('string'), 'original_tx_123')
            ->once()
            ->andReturn([
                'status' => 'declined',
                'code' => '100',
            ]);

        $result = $this->service->voidAttempt($attempt);

        $this->assertFalse($result);

        $attempt->refresh();
        $debtor->refresh();

        $this->assertEquals(BillingAttempt::STATUS_APPROVED, $attempt->status);
        $this->assertEquals(Debtor::STATUS_APPROVED, $debtor->status);
    }

    public function test_webhook_relay_is_used_when_available(): void
    {
        $account = \App\Models\EmpAccount::factory()->create();

        $relay = \App\Models\WebhookRelay::factory()->create([
            'domain' => 'http://my-disposable-relay.com/',
        ]);

        // Connect the models via the pivot table
        $relay->empAccounts()->attach($account->id);

        $upload = Upload::factory()->create([
            'emp_account_id' => $account->id,
        ]);

        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'validation_status' => 'valid',
            'status' => Debtor::STATUS_UPLOADED,
            'amount' => 50.00,
            'iban' => 'DE89370400440532013000',
        ]);

        $partialService = Mockery::mock(EmpBillingService::class . '[getClientForDebtor]', [
            $this->mockClient,
            $this->mockDescriptorService
        ]);

        // Add this line to allow mocking the protected method
        $partialService->shouldAllowMockingProtectedMethods();

        $partialService->shouldReceive('getClientForDebtor')
            ->andReturn($this->mockClient);

        $this->mockClient->shouldReceive('sddSale')
            ->once()
            ->andReturn([
                'status' => 'approved',
                'unique_id' => 'relay_test_123',
            ]);

        $result = $partialService->billDebtor($debtor);

        $this->assertInstanceOf(BillingAttempt::class, $result);
        $this->assertEquals(
            'https://my-disposable-relay.com',
            $result->request_payload['notification_url']
        );
    }

    // ==================== getClientForDebtor RESOLUTION TESTS ====================

    public function test_getClientForDebtor_uses_debtor_emp_account_id(): void
    {
        $account = EmpAccount::factory()->create(['is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => null]);
        $debtor  = Debtor::factory()->create([
            'upload_id'      => $upload->id,
            'emp_account_id' => $account->id,
        ]);

        $testableService = new class($this->mockClient, $this->mockDescriptorService) extends EmpBillingService {
            public function testGetClientForDebtor(Debtor $debtor): EmpClient
            {
                return $this->getClientForDebtor($debtor);
            }
        };

        $client = $testableService->testGetClientForDebtor($debtor->load('upload'));

        // The returned client should be built from the debtor's account, not the default mock
        $this->assertEquals($account->id, $client->getEmpAccountId());
    }

    public function test_getClientForDebtor_falls_back_to_upload_emp_account_id(): void
    {
        $uploadAccount = EmpAccount::factory()->create(['is_active' => true]);
        $upload        = Upload::factory()->create(['emp_account_id' => $uploadAccount->id]);
        $debtor        = Debtor::factory()->create([
            'upload_id'      => $upload->id,
            'emp_account_id' => null,
        ]);

        $testableService = new class($this->mockClient, $this->mockDescriptorService) extends EmpBillingService {
            public function testGetClientForDebtor(Debtor $debtor): EmpClient
            {
                return $this->getClientForDebtor($debtor);
            }
        };

        $client = $testableService->testGetClientForDebtor($debtor->load('upload'));

        $this->assertEquals($uploadAccount->id, $client->getEmpAccountId());
    }

    public function test_getClientForDebtor_falls_back_to_default_client(): void
    {
        $upload = Upload::factory()->create(['emp_account_id' => null]);
        $debtor = Debtor::factory()->create([
            'upload_id'      => $upload->id,
            'emp_account_id' => null,
        ]);

        $testableService = new class($this->mockClient, $this->mockDescriptorService) extends EmpBillingService {
            public function testGetClientForDebtor(Debtor $debtor): EmpClient
            {
                return $this->getClientForDebtor($debtor);
            }
        };

        $client = $testableService->testGetClientForDebtor($debtor->load('upload'));

        // Default mock client returns null for getEmpAccountId
        $this->assertNull($client->getEmpAccountId());
    }

    // ==================== resolveTetherInstanceId TESTS ====================

    public function test_resolveTetherInstanceId_returns_debtor_tether_instance_id(): void
    {
        $instance = TetherInstance::factory()->create();
        $upload   = Upload::factory()->create(['tether_instance_id' => null]);
        $debtor   = Debtor::factory()->create([
            'upload_id'          => $upload->id,
            'tether_instance_id' => $instance->id,
        ]);

        $method = new \ReflectionMethod($this->service, 'resolveTetherInstanceId');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $debtor->load('upload'));

        $this->assertEquals($instance->id, $result);
    }

    public function test_resolveTetherInstanceId_falls_through_to_upload_tether_instance_id(): void
    {
        $instance = TetherInstance::factory()->create();
        $upload   = Upload::factory()->create(['tether_instance_id' => $instance->id]);
        $debtor   = Debtor::factory()->create([
            'upload_id'          => $upload->id,
            'tether_instance_id' => null,
        ]);

        $method = new \ReflectionMethod($this->service, 'resolveTetherInstanceId');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $debtor->load('upload'));

        $this->assertEquals($instance->id, $result);
    }

    public function test_resolveTetherInstanceId_returns_null_when_neither_is_set(): void
    {
        $upload = Upload::factory()->create(['tether_instance_id' => null]);
        $debtor = Debtor::factory()->create([
            'upload_id'          => $upload->id,
            'tether_instance_id' => null,
        ]);

        $method = new \ReflectionMethod($this->service, 'resolveTetherInstanceId');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, $debtor->load('upload'));

        $this->assertNull($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
