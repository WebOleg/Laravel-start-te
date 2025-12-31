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
}
