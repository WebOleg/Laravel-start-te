<?php

/**
 * Unit tests for IbanApiService.
 */

namespace Tests\Unit\Services;

use App\Services\IbanApiService;
use App\Models\BankReference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IbanApiServiceTest extends TestCase
{
    use RefreshDatabase;

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
                    'bank_code' => '37040044',
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
    }

    public function test_verify_caches_to_database(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Test Bank', 'bic' => 'TESTDE00', 'bank_code' => '37040044'],
                'sepa_data' => ['SDD' => 'YES', 'SCT' => 'YES'],
                'validations' => ['iban' => ['code' => '001', 'message' => 'OK']],
                'errors' => [],
            ], 200),
        ]);

        $this->service->verify('DE89370400440532013000');

        $this->assertDatabaseHas('bank_references', [
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Test Bank',
        ]);
    }

    public function test_verify_uses_local_cache(): void
    {
        BankReference::create([
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Cached Bank',
            'bic' => 'CACHEDXX',
            'sepa_sdd' => true,
        ]);

        Http::fake();

        $result = $this->service->verify('DE89370400440532013000');

        $this->assertTrue($result['success']);
        $this->assertEquals('Cached Bank', $result['bank_data']['bank']);
        $this->assertEquals('database', $result['source']);
        
        Http::assertNothingSent();
    }

    public function test_verify_returns_error_on_api_failure(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([], 500),
        ]);

        $result = $this->service->verify('DE89370400440532013000', skipLocalCache: true);

        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
    }

    public function test_verify_returns_error_on_api_error_response(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'errors' => [['code' => '301', 'message' => 'API Key is invalid']],
            ], 200),
        ]);

        $result = $this->service->verify('DE89370400440532013000', skipLocalCache: true);

        $this->assertFalse($result['success']);
        $this->assertEquals('API Key is invalid', $result['error']);
    }

    public function test_get_bank_name_returns_bank(): void
    {
        BankReference::create([
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Deutsche Bank',
            'bic' => 'DEUTDEFF',
        ]);

        $bankName = $this->service->getBankName('DE89370400440532013000');

        $this->assertEquals('Deutsche Bank', $bankName);
    }

    public function test_get_bic_returns_bic(): void
    {
        BankReference::create([
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Deutsche Bank',
            'bic' => 'DEUTDEFF',
        ]);

        $bic = $this->service->getBic('DE89370400440532013000');

        $this->assertEquals('DEUTDEFF', $bic);
    }

    public function test_is_valid_returns_true_for_valid_iban(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank_code' => '37040044'],
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
                'bank_data' => ['bank_code' => '37040044'],
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
        BankReference::create([
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Test Bank',
            'sepa_sdd' => true,
        ]);

        $this->assertTrue($this->service->supportsSepaSdd('DE89370400440532013000'));
    }

    public function test_supports_sepa_sdd_returns_false(): void
    {
        BankReference::create([
            'country_iso' => 'DE',
            'bank_code' => '37040044',
            'bank_name' => 'Test Bank',
            'sepa_sdd' => false,
        ]);

        $this->assertFalse($this->service->supportsSepaSdd('DE89370400440532013000'));
    }

    public function test_normalizes_iban_before_request(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'bank_data' => ['bank' => 'Test', 'bank_code' => '37040044'],
                'sepa_data' => [],
                'validations' => [],
                'errors' => [],
            ], 200),
        ]);

        $this->service->verify('de89 3704 0044 0532 0130 00', skipLocalCache: true);

        Http::assertSent(function ($request) {
            return $request['iban'] === 'DE89370400440532013000';
        });
    }

    public function test_mock_mode_returns_mock_data(): void
    {
        config(['services.iban.mock' => true]);
        $service = new IbanApiService();

        Http::fake();

        $result = $service->verify('DE89370400440532013000', skipLocalCache: true);

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['bank_data']['bank']);
        
        Http::assertNothingSent();
    }
}
