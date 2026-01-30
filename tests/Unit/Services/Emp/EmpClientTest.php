<?php

namespace Tests\Unit\Services\Emp;

use App\Services\Emp\EmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmpClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        config([
            'services.emp.endpoint' => 'staging.gate.emerchantpay.net',
            'services.emp.username' => 'test_username',
            'services.emp.password' => 'test_password',
            'services.emp.terminal_token' => 'test_terminal_token',
        ]);
    }

    public function test_sdd_sale_builds_correct_xml(): void
    {
        Http::fake([
            '*' => Http::response($this->mockSuccessResponse(), 200),
        ]);

        $client = new EmpClient();

        $result = $client->sddSale([
            'transaction_id' => 'tether_123_20251228_abc12345',
            'amount' => 99.99,
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
            'first_name' => 'Max',
            'last_name' => 'Mustermann',
            'email' => 'max@test.de',
            'notification_url' => 'https://example.com/webhook',
        ]);

        $this->assertEquals('approved', $result['status']);
        $this->assertNotEmpty($result['unique_id']);
        
        Http::assertSent(function ($request) {
            $body = $request->body();
            return str_contains($body, '<transaction_type>sdd_sale</transaction_type>')
                && str_contains($body, '<iban>DE89370400440532013000</iban>')
                && str_contains($body, '<amount>9999</amount>');
        });
    }

    public function test_sdd_sale_handles_pending_async(): void
    {
        Http::fake([
            '*' => Http::response($this->mockPendingAsyncResponse(), 200),
        ]);

        $client = new EmpClient();

        $result = $client->sddSale([
            'transaction_id' => 'tether_123',
            'amount' => 50.00,
            'iban' => 'FR7630006000011234567890189',
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $this->assertEquals('pending_async', $result['status']);
        $this->assertNotEmpty($result['redirect_url']);
    }

    public function test_sdd_sale_handles_declined(): void
    {
        Http::fake([
            '*' => Http::response($this->mockDeclinedResponse(), 200),
        ]);

        $client = new EmpClient();

        $result = $client->sddSale([
            'transaction_id' => 'tether_456',
            'amount' => 100.00,
            'iban' => 'DE89370400440532013000',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);

        $this->assertEquals('declined', $result['status']);
        $this->assertNotEmpty($result['technical_message']);
    }

    public function test_sdd_sale_handles_network_error(): void
    {
        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $client = new EmpClient();

        $result = $client->sddSale([
            'transaction_id' => 'tether_789',
            'amount' => 25.00,
            'iban' => 'ES9121000418450200051332',
            'first_name' => 'Carlos',
            'last_name' => 'Garcia',
        ]);

        $this->assertEquals('error', $result['status']);
    }

    public function test_verify_signature_success(): void
    {
        $client = new EmpClient();
        
        $uniqueId = '44177a21403427eb96664a6d7e5d5d48';
        $password = config('services.emp.password');
        $validSignature = hash('sha1', $uniqueId . $password);

        $this->assertTrue($client->verifySignature($uniqueId, $validSignature));
    }

    public function test_verify_signature_failure(): void
    {
        $client = new EmpClient();
        
        $uniqueId = '44177a21403427eb96664a6d7e5d5d48';
        $invalidSignature = 'invalid_signature_hash';

        $this->assertFalse($client->verifySignature($uniqueId, $invalidSignature));
    }

    public function test_reconcile_request(): void
    {
        Http::fake([
            '*' => Http::response($this->mockReconcileResponse(), 200),
        ]);

        $client = new EmpClient();

        $result = $client->reconcile('44177a21403427eb96664a6d7e5d5d48');

        $this->assertEquals('approved', $result['status']);
        
        Http::assertSent(function ($request) {
            return str_contains($request->body(), '<unique_id>44177a21403427eb96664a6d7e5d5d48</unique_id>');
        });
    }

    private function mockSuccessResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <payment_response>
                <transaction_type>sdd_sale</transaction_type>
                <status>approved</status>
                <unique_id>44177a21403427eb96664a6d7e5d5d48</unique_id>
                <transaction_id>tether_123_20251228_abc12345</transaction_id>
                <amount>9999</amount>
                <currency>EUR</currency>
                <message>Transaction successful!</message>
            </payment_response>';
    }

    private function mockPendingAsyncResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <payment_response>
                <transaction_type>sdd_sale</transaction_type>
                <status>pending_async</status>
                <unique_id>abc123def456</unique_id>
                <transaction_id>tether_123</transaction_id>
                <redirect_url>https://staging.gate.emerchantpay.net/redirect/abc123</redirect_url>
                <amount>5000</amount>
                <currency>EUR</currency>
            </payment_response>';
    }

    private function mockDeclinedResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <payment_response>
                <transaction_type>sdd_sale</transaction_type>
                <status>declined</status>
                <unique_id>declined123</unique_id>
                <transaction_id>tether_456</transaction_id>
                <code>340</code>
                <technical_message>Invalid IBAN</technical_message>
                <message>Transaction declined</message>
            </payment_response>';
    }

    private function mockReconcileResponse(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>
            <payment_response>
                <status>approved</status>
                <unique_id>44177a21403427eb96664a6d7e5d5d48</unique_id>
                <amount>9999</amount>
                <currency>EUR</currency>
            </payment_response>';
    }
}
