<?php

/**
 * Unit tests for IbanValidator service.
 */

namespace Tests\Unit\Services;

use App\Services\IbanValidator;
use PHPUnit\Framework\TestCase;

class IbanValidatorTest extends TestCase
{
    private IbanValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IbanValidator();
    }

    public function test_validates_correct_german_iban(): void
    {
        $result = $this->validator->validate('DE89370400440532013000');

        $this->assertTrue($result['valid']);
        $this->assertEquals('DE', $result['country_code']);
        $this->assertEquals('Germany', $result['country_name']);
        $this->assertTrue($result['is_sepa']);
        $this->assertEquals('89', $result['checksum']);
        $this->assertEmpty($result['errors']);
    }

    public function test_validates_correct_spanish_iban(): void
    {
        $result = $this->validator->validate('ES9121000418450200051332');

        $this->assertTrue($result['valid']);
        $this->assertEquals('ES', $result['country_code']);
        $this->assertEquals('Spain', $result['country_name']);
        $this->assertTrue($result['is_sepa']);
    }

    public function test_validates_correct_french_iban(): void
    {
        $result = $this->validator->validate('FR1420041010050500013M02606');

        $this->assertTrue($result['valid']);
        $this->assertEquals('FR', $result['country_code']);
        $this->assertTrue($result['is_sepa']);
    }

    public function test_validates_iban_with_spaces(): void
    {
        $result = $this->validator->validate('DE89 3704 0044 0532 0130 00');

        $this->assertTrue($result['valid']);
        $this->assertEquals('DE', $result['country_code']);
    }

    public function test_validates_lowercase_iban(): void
    {
        $result = $this->validator->validate('de89370400440532013000');

        $this->assertTrue($result['valid']);
        $this->assertEquals('DE', $result['country_code']);
    }

    public function test_rejects_invalid_checksum(): void
    {
        $result = $this->validator->validate('DE00370400440532013000');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_rejects_invalid_length(): void
    {
        $result = $this->validator->validate('DE8937040044053201300');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_rejects_invalid_country(): void
    {
        $result = $this->validator->validate('XX89370400440532013000');

        $this->assertFalse($result['valid']);
    }

    public function test_rejects_empty_string(): void
    {
        $result = $this->validator->validate('');

        $this->assertFalse($result['valid']);
    }

    public function test_is_valid_returns_boolean(): void
    {
        $this->assertTrue($this->validator->isValid('DE89370400440532013000'));
        $this->assertFalse($this->validator->isValid('DE00370400440532013000'));
        $this->assertFalse($this->validator->isValid('invalid'));
    }

    public function test_is_sepa_detects_sepa_countries(): void
    {
        $this->assertTrue($this->validator->isSepa('DE89370400440532013000'));
        $this->assertTrue($this->validator->isSepa('ES9121000418450200051332'));
        $this->assertTrue($this->validator->isSepa('FR1420041010050500013M02606'));
        $this->assertTrue($this->validator->isSepa('NL91ABNA0417164300'));
    }

    public function test_get_country_code(): void
    {
        $this->assertEquals('DE', $this->validator->getCountryCode('DE89370400440532013000'));
        $this->assertEquals('ES', $this->validator->getCountryCode('ES9121000418450200051332'));
        $this->assertEquals('FR', $this->validator->getCountryCode('FR1420041010050500013M02606'));
    }

    public function test_get_country_name(): void
    {
        $this->assertEquals('Germany', $this->validator->getCountryName('DE89370400440532013000'));
        $this->assertEquals('Spain', $this->validator->getCountryName('ES9121000418450200051332'));
    }

    public function test_get_bank_id(): void
    {
        $bankId = $this->validator->getBankId('DE89370400440532013000');
        $this->assertEquals('37040044', $bankId);
    }

    public function test_normalize_removes_spaces_and_special_chars(): void
    {
        $this->assertEquals('DE89370400440532013000', $this->validator->normalize('DE89 3704 0044 0532 0130 00'));
        $this->assertEquals('DE89370400440532013000', $this->validator->normalize('de89-3704-0044-0532-0130-00'));
        $this->assertEquals('DE89370400440532013000', $this->validator->normalize('IBAN DE89 3704 0044 0532 0130 00'));
        $this->assertEquals('DE89370400440532013000', $this->validator->normalize('IBAN DE89370400440532013000'));
    }

    public function test_format_adds_spaces(): void
    {
        $formatted = $this->validator->format('DE89370400440532013000');
        $this->assertEquals('DE89 3704 0044 0532 0130 00', $formatted);
    }

    public function test_mask_hides_middle_part(): void
    {
        $masked = $this->validator->mask('DE89370400440532013000');
        
        $this->assertStringStartsWith('DE89', $masked);
        $this->assertStringEndsWith('3000', $masked);
        $this->assertStringContainsString('*', $masked);
        // DE IBAN = 22 chars, mask keeps first 4 + last 4, so 14 asterisks
        $this->assertEquals(22, strlen($masked));
    }

    public function test_mask_short_iban(): void
    {
        $masked = $this->validator->mask('DE89');
        $this->assertEquals('****', $masked);
    }

    public function test_hash_generates_consistent_sha256(): void
    {
        $hash1 = $this->validator->hash('DE89370400440532013000');
        $hash2 = $this->validator->hash('DE89 3704 0044 0532 0130 00');
        $hash3 = $this->validator->hash('de89370400440532013000');

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals($hash1, $hash3);
        $this->assertEquals(64, strlen($hash1));
    }
}
