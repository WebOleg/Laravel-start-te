<?php

/**
 * Unit tests for IbanApiService.
 */

namespace Tests\Unit\Services;

use App\Services\IbanApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IbanApiServiceTest extends TestCase
{
    private IbanApiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        config(['services.iban.mock' => false]);
        config(['services.iban.api_key' => 'test_key']);
        config(['services.iban.api_url' => 'https://api.iban.com/clients/api/v4/iban/']);
        
        Cache::flush();
        
        $this->service = new IbanApiService();
    }

    public function test_verify_returns_bank_data_on_success(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => [
                    'bic' => 'COBADEFFXXX',
                    'bank' => 'Commerzbank',
                    'country_iso' => 'DE',
                ],
                'sepa_data' => [
                    'SCT' => 'YES',
                    'SDD' => 'YES',
                ],
                'validations' => [
                    'iban' => ['code' => '001', 'message' => 'IBAN Check digit is correct'],
                ],
                'errors' => [],
            ], 200),
        ]);

        $result = $this->service->verify('DE89370400440532013000');

        $this->assertTrue($result['success']);
        $this->assertEquals('Commerzbank', $result['bank_data']['bank']);
        $this->assertEquals('COBADEFFXXX', $result['bank_data']['bic']);
        $this->assertEquals('YES', $result['sepa_data']['SDD']);
        $this->assertFalse($result['cached']);
    }

    public function test_verify_caches_successful_response(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Test Bank', 'bic' => 'TESTDE00'],
                'sepa_data' => ['SDD' => 'YES'],
                'validations' => ['iban' => ['code' => '001', 'message' => 'OK']],
                'errors' => [],
            ], 200),
        ]);

        $result1 = $this->service->verify('DE89370400440532013000');
        $result2 = $this->service->verify('DE89370400440532013000');

        $this->assertFalse($result1['cached']);
        $this->assertTrue($result2['cached']);
        
        Http::assertSentCount(1);
    }

    public function test_verify_returns_error_on_api_failure(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([], 500),
        ]);

        $result = $this->service->verify('DE89370400440532013000');

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertNull($result['bank_data']);
    }

    public function test_verify_returns_error_on_api_error_response(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'errors' => [['code' => '301', 'message' => 'API Key is invalid']],
            ], 200),
        ]);

        $result = $this->service->verify('DE89370400440532013000');

        $this->assertFalse($result['success']);
        $this->assertEquals('API Key is invalid', $result['error']);
    }

    public function test_get_bank_name_returns_bank(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Deutsche Bank', 'bic' => 'DEUTDEFF'],
                'sepa_data' => [],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $bankName = $this->service->getBankName('DE89370400440532013000');

        $this->assertEquals('Deutsche Bank', $bankName);
    }

    public function test_get_bic_returns_bic(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Deutsche Bank', 'bic' => 'DEUTDEFF'],
                'sepa_data' => [],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $bic = $this->service->getBic('DE89370400440532013000');

        $this->assertEquals('DEUTDEFF', $bic);
    }

    public function test_is_valid_returns_true_for_valid_iban(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => [],
                'sepa_data' => [],
                'validations' => [
                    'iban' => ['code' => '001', 'message' => 'IBAN Check digit is correct'],
                ],
                'errors' => [],
            ], 200),
        ]);

        $this->assertTrue($this->service->isValid('DE89370400440532013000'));
    }

    public function test_is_valid_returns_false_for_invalid_iban(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => [],
                'sepa_data' => [],
                'validations' => [
                    'iban' => ['code' => '202', 'message' => 'IBAN Check digit not correct'],
                ],
                'errors' => [],
            ], 200),
        ]);

        $this->assertFalse($this->service->isValid('DE89370400440532013001'));
    }

    public function test_supports_sepa_sdd_returns_true(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => [],
                'sepa_data' => ['SDD' => 'YES'],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $this->assertTrue($this->service->supportsSepaSdd('DE89370400440532013000'));
    }

    public function test_supports_sepa_sdd_returns_false(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => [],
                'sepa_data' => ['SDD' => 'NO'],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $this->assertFalse($this->service->supportsSepaSdd('DE89370400440532013000'));
    }

    public function test_normalizes_iban_before_request(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Test'],
                'sepa_data' => [],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $this->service->verify('de89 3704 0044 0532 0130 00');

        Http::assertSent(function ($request) {
            return $request['iban'] === 'DE89370400440532013000';
        });
    }

    public function test_mock_mode_returns_mock_data(): void
    {
        config(['services.iban.mock' => true]);
        $service = new IbanApiService();

        Http::fake();

        $result = $service->verify('DE89370400440532013000');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['bank_data']['bank']);
        
        Http::assertNothingSent();
    }
}
