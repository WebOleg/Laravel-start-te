<?php

/**
 * Unit tests for DebtorValidationService.
 */

namespace Tests\Unit\Services;

use App\Models\Debtor;
use App\Models\Upload;
use App\Services\DebtorValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtorValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DebtorValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DebtorValidationService::class);
    }

    public function test_validates_valid_debtor(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertEmpty($errors);
    }

    public function test_first_name_exceeds_max_length(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => str_repeat('A', 36),
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('First name cannot exceed 35 characters', $errors);
    }

    public function test_last_name_exceeds_max_length(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => str_repeat('B', 36),
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Last name cannot exceed 35 characters', $errors);
    }

    public function test_name_exactly_35_chars_is_valid(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => str_repeat('A', 35),
            'last_name' => str_repeat('B', 35),
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('First name cannot exceed 35 characters', $errors);
        $this->assertNotContains('Last name cannot exceed 35 characters', $errors);
    }

    public function test_both_names_exceed_max_length(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => str_repeat('A', 40),
            'last_name' => str_repeat('B', 40),
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('First name cannot exceed 35 characters', $errors);
        $this->assertContains('Last name cannot exceed 35 characters', $errors);
    }

    public function test_validates_amount_positive(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'iban' => 'DE89370400440532013000',
            'amount' => -50,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount must be at least 1.00', $errors);
    }

    public function test_validates_amount_max(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'iban' => 'DE89370400440532013000',
            'amount' => 60000,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount exceeds maximum limit (50,000)', $errors);
    }

    public function test_validate_and_update_sets_valid_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $this->service->validateAndUpdate($debtor);

        $this->assertEquals(Debtor::VALIDATION_VALID, $debtor->validation_status);
        $this->assertNull($debtor->validation_errors);
        $this->assertNotNull($debtor->validated_at);
    }

    public function test_validate_and_update_sets_invalid_status(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => str_repeat('A', 40),
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $this->service->validateAndUpdate($debtor);

        $this->assertEquals(Debtor::VALIDATION_INVALID, $debtor->validation_status);
        $this->assertNotNull($debtor->validation_errors);
        $this->assertContains('First name cannot exceed 35 characters', $debtor->validation_errors);
    }

    public function test_validates_amount_below_minimum(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'iban' => 'DE89370400440532013000',
            'amount' => 0.50,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount must be at least 1.00', $errors);
    }
    
    public function test_validates_amount_exactly_one_euro(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 1.00,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('Amount must be at least 1.00', $errors);
    }

    public function test_resolve_bic_from_iban_when_bic_is_empty(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => null,
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
        $mockIbanApiService->shouldReceive('getBic')
            ->once()
            ->with('DE89370400440532013000')
            ->andReturn('COBADEFFXXX');

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertEquals('COBADEFFXXX', $debtor->bic);
    }

    public function test_resolve_bic_from_iban_skips_when_bic_exists(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => 'EXISTINGBIC',
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
        $mockIbanApiService->shouldNotReceive('getBic');

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertEquals('EXISTINGBIC', $debtor->bic);
    }

    public function test_resolve_bic_from_iban_handles_null_response(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => null,
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
        $mockIbanApiService->shouldReceive('getBic')
            ->once()
            ->with('DE89370400440532013000')
            ->andReturn(null);

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertNull($debtor->bic);
    }

    public function test_resolve_bic_from_iban_handles_empty_string_response(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => null,
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
        $mockIbanApiService->shouldReceive('getBic')
            ->once()
            ->with('DE89370400440532013000')
            ->andReturn('');

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertNull($debtor->bic);
    }

    public function test_resolve_bic_from_iban_handles_exception(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => null,
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
        $mockIbanApiService->shouldReceive('getBic')
            ->once()
            ->with('DE89370400440532013000')
            ->andThrow(new \Exception('API connection failed'));

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertNull($debtor->bic);
        // Test should not fail - exception is caught and logged
    }

    public function test_resolve_bic_from_iban_with_different_countries(): void
    {
        $testCases = [
            ['iban' => 'FR1420041010050500013M02606', 'expected_bic' => 'BNPAFRPPXXX'],
            ['iban' => 'NL91ABNA0417164300', 'expected_bic' => 'ABNANL2AXXX'],
            ['iban' => 'BE68539007547034', 'expected_bic' => 'GEBABEBB'],
        ];

        foreach ($testCases as $testCase) {
            $upload = Upload::factory()->create();
            $debtor = Debtor::factory()->create([
                'upload_id' => $upload->id,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'iban' => $testCase['iban'],
                'bic' => null,
                'amount' => 100,
            ]);

            $mockIbanApiService = $this->mock(\App\Services\IbanApiService::class);
            $mockIbanApiService->shouldReceive('getBic')
                ->once()
                ->with($testCase['iban'])
                ->andReturn($testCase['expected_bic']);

            $service = app(DebtorValidationService::class);
            $errors = $service->validateDebtor($debtor);

            $debtor->refresh();
            $this->assertEquals($testCase['expected_bic'], $debtor->bic, "Failed for IBAN: {$testCase['iban']}");
        }
    }
}
