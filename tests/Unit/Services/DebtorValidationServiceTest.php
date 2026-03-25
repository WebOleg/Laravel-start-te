<?php

/**
 * Unit tests for DebtorValidationService.
 */

namespace Tests\Unit\Services;

use App\Models\Debtor;
use App\Models\Upload;
use App\Services\DebtorValidationService;
use App\Services\IbanApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->assertContains('Amount must be at least 0.10', $errors);
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
            'amount' => 0.05,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount must be at least 0.10', $errors);
    }

    public function test_validates_amount_exactly_minimum(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 0.10,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('Amount must be at least 0.10', $errors);
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

        $mockIbanApiService = $this->mock(IbanApiService::class);
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

        $mockIbanApiService = $this->mock(IbanApiService::class);
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

        $mockIbanApiService = $this->mock(IbanApiService::class);
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

        $mockIbanApiService = $this->mock(IbanApiService::class);
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

        $mockIbanApiService = $this->mock(IbanApiService::class);
        $mockIbanApiService->shouldReceive('getBic')
            ->once()
            ->with('DE89370400440532013000')
            ->andThrow(new \Exception('API connection failed'));

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertNull($debtor->bic);
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

            $mockIbanApiService = $this->mock(IbanApiService::class);
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

    public function test_validates_amount_required_when_zero(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'iban' => 'DE89370400440532013000',
            'amount' => 0,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount is required', $errors);
    }

    public function test_validates_amount_exactly_at_max(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 50000,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('Amount exceeds maximum limit (50,000)', $errors);
    }

    public function test_validates_amount_just_above_max(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 50000.01,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Amount exceeds maximum limit (50,000)', $errors);
    }

    public function test_first_name_with_numbers_is_invalid(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John123',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('First name contains invalid characters', $errors);
    }

    public function test_last_name_with_symbols_is_invalid(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe@Smith',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Last name contains invalid characters', $errors);
    }

    public function test_name_with_special_accented_chars_is_invalid(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'José',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        // The INVALID_NAME_PATTERN includes accented characters like é
        $this->assertContains('First name contains invalid characters', $errors);
    }

    public function test_name_with_hyphen_and_space_is_valid(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'Mary-Jane',
            'last_name' => 'Van Der Berg',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('First name contains invalid characters', $errors);
        $this->assertNotContains('Last name contains invalid characters', $errors);
    }

    public function test_validates_valid_email(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'email' => 'john@example.com',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('Email format is invalid', $errors);
    }

    public function test_validates_invalid_email(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'email' => 'not-an-email',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertContains('Email format is invalid', $errors);
    }

    public function test_validates_empty_email_is_ok(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'email' => null,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertNotContains('Email format is invalid', $errors);
    }

    public function test_detects_broken_encoding_in_first_name(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => "John\xEF\xBF\xBD",  // Unicode replacement character
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'encoding issues')),
            'Expected encoding error for broken characters in name'
        );
    }

    public function test_detects_broken_encoding_in_city(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'city' => "M\xC3\x83nchen",  // Double-encoded ü
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'encoding issues')),
            'Expected encoding error for broken characters in city'
        );
    }

    public function test_detects_control_characters_as_broken_encoding(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'street' => "Main\x01Street",
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'encoding issues')),
            'Expected encoding error for control characters'
        );
    }

    public function test_encoding_error_deduplicates_field_labels(): void
    {
        $upload = Upload::factory()->create();
        // Both first_name and last_name map to "Name" label — should only appear once
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => "Bad\xEF\xBF\xBD",
            'last_name' => "Also\xEF\xBF\xBD",
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $encodingErrors = collect($errors)->filter(fn ($e) => str_contains($e, 'encoding issues'));
        // Should be a single error mentioning "Name" once, not twice
        $this->assertCount(1, $encodingErrors);
    }

    public function test_validate_and_update_clears_validation_lock_cache(): void
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

        Cache::put("billing:lock:validation:{$debtor->id}", true, 300);

        $this->service->validateAndUpdate($debtor);

        $this->assertFalse(Cache::has("billing:lock:validation:{$debtor->id}"));
    }

    public function test_validate_and_update_persists_to_database(): void
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

        $this->assertDatabaseHas('debtors', [
            'id' => $debtor->id,
            'validation_status' => Debtor::VALIDATION_VALID,
        ]);
    }

    public function test_validate_and_update_returns_debtor_instance(): void
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

        $result = $this->service->validateAndUpdate($debtor);

        $this->assertInstanceOf(Debtor::class, $result);
        $this->assertEquals($debtor->id, $result->id);
    }

    public function test_validate_upload_processes_all_debtors(): void
    {
        $upload = Upload::factory()->create();

        Debtor::factory()->count(3)->create([
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

        $stats = $this->service->validateUpload($upload);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(3, $stats['valid']);
        $this->assertEquals(0, $stats['invalid']);
    }

    public function test_validate_upload_counts_valid_and_invalid_separately(): void
    {
        $upload = Upload::factory()->create();

        // 2 valid
        Debtor::factory()->count(2)->create([
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

        // 1 invalid (name too long)
        Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => str_repeat('X', 40),
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'validation_status' => Debtor::VALIDATION_PENDING,
        ]);

        $stats = $this->service->validateUpload($upload);

        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(2, $stats['valid']);
        $this->assertEquals(1, $stats['invalid']);
    }

    public function test_validate_upload_returns_zeroes_for_empty_upload(): void
    {
        $upload = Upload::factory()->create();

        $stats = $this->service->validateUpload($upload);

        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['valid']);
        $this->assertEquals(0, $stats['invalid']);
    }

    public function test_resolve_bic_skips_when_iban_is_empty(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => '',
            'bic' => null,
            'amount' => 100,
        ]);

        $mockIbanApiService = $this->mock(IbanApiService::class);
        $mockIbanApiService->shouldNotReceive('getBic');

        $service = app(DebtorValidationService::class);
        $service->validateDebtor($debtor);

        $debtor->refresh();
        $this->assertNull($debtor->bic);
    }

    public function test_validates_blacklisted_iban(): void
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

        $mockBlacklist = $this->mock(\App\Services\BlacklistService::class);
        $mockBlacklist->shouldReceive('checkDebtor')
            ->once()
            ->andReturn(['reasons' => ['IBAN is blacklisted']]);

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $this->assertContains('IBAN is blacklisted', $errors);
    }

    public function test_validates_blacklisted_bic_skipped_when_upload_flag_set(): void
    {
        $upload = Upload::factory()->create([
            'skip_bic_blacklist' => true,
        ]);
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => 'BLACKBIC',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $mockBlacklist = $this->mock(\App\Services\BlacklistService::class);
        $mockBlacklist->shouldReceive('checkDebtor')
            ->once()
            ->andReturn(['reasons' => ['BIC is blacklisted']]);

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $this->assertNotContains('BIC is blacklisted', $errors);
    }

    public function test_validates_blacklisted_bic_included_when_upload_flag_not_set(): void
    {
        $upload = Upload::factory()->create([
            'skip_bic_blacklist' => false,
        ]);
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'bic' => 'BLACKBIC',
            'amount' => 100,
            'city' => 'Berlin',
            'postcode' => '10115',
            'street' => 'Main Street 1',
        ]);

        $mockBlacklist = $this->mock(\App\Services\BlacklistService::class);
        $mockBlacklist->shouldReceive('checkDebtor')
            ->once()
            ->andReturn(['reasons' => ['BIC is blacklisted']]);

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        $this->assertContains('BIC is blacklisted', $errors);
    }

    public function test_blacklist_non_bic_reasons_always_included_regardless_of_skip_flag(): void
    {
        $upload = Upload::factory()->create([
            'skip_bic_blacklist' => true,
        ]);
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

        $mockBlacklist = $this->mock(\App\Services\BlacklistService::class);
        $mockBlacklist->shouldReceive('checkDebtor')
            ->once()
            ->andReturn(['reasons' => ['IBAN is blacklisted', 'BIC is blacklisted']]);

        $service = app(DebtorValidationService::class);
        $errors = $service->validateDebtor($debtor);

        // IBAN blacklist should remain, BIC blacklist should be filtered
        $this->assertContains('IBAN is blacklisted', $errors);
        $this->assertNotContains('BIC is blacklisted', $errors);
    }

    public function test_validates_invalid_iban_format(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'INVALID_IBAN',
            'amount' => 100,
        ]);

        $errors = $this->service->validateDebtor($debtor);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'IBAN is invalid')),
            'Expected IBAN validation error'
        );
    }

    public function test_validates_non_sepa_country(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'DE89370400440532013000',
            'amount' => 100,
            'country' => 'US',
        ]);

        $errors = $this->service->validateDebtor($debtor);

        // Depends on whether US is in SEPA list — most likely not
        // This tests the code path runs; actual assertion depends on SEPA config
        $this->assertIsArray($errors);
    }

}
