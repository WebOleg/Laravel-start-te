<?php

namespace Tests\Feature;

use App\Services\IbanBavService;
use App\Services\IbanValidator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IbanBavServiceTest extends TestCase
{
    private IbanBavService $bavService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bavService = app(IbanBavService::class);
    }

    public function test_supported_countries_list(): void
    {
        $countries = $this->bavService->getSupportedCountries();

        $this->assertContains('DE', $countries);
        $this->assertContains('NL', $countries);
        $this->assertContains('ES', $countries);
        $this->assertContains('FR', $countries);
        $this->assertNotContains('GB', $countries);
        $this->assertNotContains('US', $countries);
    }

    public function test_country_support_check(): void
    {
        $this->assertTrue($this->bavService->isCountrySupported('DE'));
        $this->assertTrue($this->bavService->isCountrySupported('nl'));
        $this->assertFalse($this->bavService->isCountrySupported('GB'));
        $this->assertFalse($this->bavService->isCountrySupported('US'));
    }

    public function test_unsupported_country_returns_error(): void
    {
        $result = $this->bavService->verify('GB33BUKB20201555555555', 'John Doe');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not supported', $result['error']);
    }

    public function test_mock_response_name_match_yes(): void
    {
        config(['services.iban.mock' => true]);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013000', 'Max Mustermann');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('yes', $result['name_match']);
        $this->assertEquals(100, $result['vop_score']);
        $this->assertEquals('verified', $result['vop_result']);
        $this->assertNotNull($result['bic']);
    }

    public function test_mock_response_name_match_partial(): void
    {
        config(['services.iban.mock' => true]);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013001', 'Max Mustermann');

        $this->assertTrue($result['success']);
        $this->assertEquals('partial', $result['name_match']);
        $this->assertEquals(70, $result['vop_score']);
        $this->assertEquals('likely_verified', $result['vop_result']);
    }

    public function test_mock_response_name_match_no(): void
    {
        config(['services.iban.mock' => true]);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013002', 'Wrong Name');

        $this->assertTrue($result['success']);
        $this->assertEquals('no', $result['name_match']);
        $this->assertEquals(20, $result['vop_score']);
        $this->assertEquals('mismatch', $result['vop_result']);
    }

    public function test_mock_response_invalid_iban(): void
    {
        config(['services.iban.mock' => true]);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013009', 'Max Mustermann');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['valid']);
        $this->assertEquals(0, $result['vop_score']);
        $this->assertEquals('rejected', $result['vop_result']);
    }

    public function test_real_api_call_structure(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'query' => [
                    'IBAN' => 'DE89370400440532013000',
                    'name' => 'MAX MUSTERMANN',
                    'success' => true,
                ],
                'result' => [
                    'valid' => true,
                    'name_match' => 'yes',
                    'bic' => 'COBADEFFXXX',
                ],
                'error' => '',
            ], 200),
        ]);

        config(['services.iban.mock' => false]);
        config(['services.iban.api_key' => 'test_key']);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013000', 'Max Mustermann');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['valid']);
        $this->assertEquals('yes', $result['name_match']);
        $this->assertEquals('COBADEFFXXX', $result['bic']);
        $this->assertEquals(100, $result['vop_score']);
        $this->assertEquals('verified', $result['vop_result']);
    }

    public function test_api_error_handling(): void
    {
        Http::fake([
            'api.iban.com/*' => Http::response([
                'query' => ['success' => false],
                'error' => 'INVALID_API_KEY',
            ], 401),
        ]);

        config(['services.iban.mock' => false]);
        $service = app(IbanBavService::class);

        $result = $service->verify('DE89370400440532013000', 'Max Mustermann');

        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_API_KEY', $result['error']);
    }

    public function test_vop_score_calculation(): void
    {
        config(['services.iban.mock' => true]);
        $service = app(IbanBavService::class);

        $resultYes = $service->verify('NL91ABNA0417164300', 'Jan de Vries');
        $this->assertEquals(100, $resultYes['vop_score']);

        $resultPartial = $service->verify('NL91ABNA0417164301', 'Jan de Vries');
        $this->assertEquals(70, $resultPartial['vop_score']);

        $resultNo = $service->verify('NL91ABNA0417164302', 'Wrong');
        $this->assertEquals(20, $resultNo['vop_score']);

        $resultInvalid = $service->verify('NL91ABNA0417164309', 'Test');
        $this->assertEquals(0, $resultInvalid['vop_score']);
    }
}
