<?php

/**
 * HTTP client for emerchantpay Genesis API.
 * Handles authentication, XML building, and request/response processing.
 */

namespace App\Services\Emp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use RuntimeException;

class EmpClient
{
    private string $endpoint;
    private string $username;
    private string $password;
    private string $terminalToken;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->endpoint = config('services.emp.endpoint');
        $this->username = config('services.emp.username');
        $this->password = config('services.emp.password');
        $this->terminalToken = config('services.emp.terminal_token');
        $this->timeout = config('services.emp.timeout', 30);
        $this->connectTimeout = config('services.emp.connect_timeout', 10);

        if (!$this->username || !$this->password || !$this->terminalToken) {
            throw new RuntimeException('EMP credentials not configured');
        }
    }

    /**
     * Send SDD Sale (SEPA Direct Debit) transaction.
     *
     * @param array $data Transaction data
     * @return array Parsed response
     */
    public function sddSale(array $data): array
    {
        $xml = $this->buildSddSaleXml($data);
        
        return $this->sendRequest('/process/' . $this->terminalToken, $xml);
    }

    /**
     * Reconcile a transaction by unique_id.
     *
     * @param string $uniqueId
     * @return array
     */
    public function reconcile(string $uniqueId): array
    {
        $xml = $this->buildReconcileXml($uniqueId);
        
        return $this->sendRequest('/reconcile/' . $this->terminalToken, $xml);
    }

    /**
     * Build XML for SDD Sale transaction.
     */
    private function buildSddSaleXml(array $data): string
    {
        $amount = (int) round($data['amount'] * 100); // Convert to cents
        
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><payment_transaction/>');
        
        $xml->addChild('transaction_type', 'sdd_sale');
        $xml->addChild('transaction_id', $data['transaction_id']);
        $xml->addChild('usage', $data['usage'] ?? 'Payment');
        $xml->addChild('remote_ip', $data['remote_ip'] ?? request()->ip() ?? '127.0.0.1');
        $xml->addChild('amount', $amount);
        $xml->addChild('currency', $data['currency'] ?? 'EUR');
        $xml->addChild('iban', $data['iban']);
        
        if (!empty($data['bic'])) {
            $xml->addChild('bic', $data['bic']);
        }
        
        // Notification URL for async responses
        if (!empty($data['notification_url'])) {
            $xml->addChild('notification_url', $data['notification_url']);
        }
        
        // Return URLs for SDDVP flow
        if (!empty($data['return_success_url'])) {
            $xml->addChild('return_success_url', $data['return_success_url']);
            $xml->addChild('return_failure_url', $data['return_failure_url'] ?? $data['return_success_url']);
            $xml->addChild('return_cancel_url', $data['return_cancel_url'] ?? $data['return_success_url']);
        }
        
        // Customer email
        if (!empty($data['email'])) {
            $xml->addChild('customer_email', $data['email']);
        }
        
        // Billing address
        $billing = $xml->addChild('billing_address');
        $billing->addChild('first_name', $this->sanitizeName($data['first_name'] ?? ''));
        $billing->addChild('last_name', $this->sanitizeName($data['last_name'] ?? ''));
        
        if (!empty($data['address'])) {
            $billing->addChild('address1', substr($data['address'], 0, 255));
        }
        if (!empty($data['zip_code'])) {
            $billing->addChild('zip_code', $data['zip_code']);
        }
        if (!empty($data['city'])) {
            $billing->addChild('city', $data['city']);
        }
        
        // Extract country from IBAN
        $country = strtoupper(substr($data['iban'], 0, 2));
        $billing->addChild('country', $country);

        return $xml->asXML();
    }

    /**
     * Build XML for reconcile request.
     */
    private function buildReconcileXml(string $uniqueId): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><reconcile/>');
        $xml->addChild('unique_id', $uniqueId);
        
        return $xml->asXML();
    }

    /**
     * Send HTTP request to EMP API.
     */
    private function sendRequest(string $path, string $xml): array
    {
        $url = 'https://' . $this->endpoint . $path;

        Log::debug('EMP request', [
            'url' => $url,
            'xml' => $xml,
        ]);

        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders([
                    'Content-Type' => 'application/xml',
                    'Accept' => 'application/xml',
                ])
                ->withBody($xml, 'application/xml')
                ->post($url);

            $parsed = $this->parseResponse($response);

            Log::debug('EMP response', [
                'status' => $response->status(),
                'parsed' => $parsed,
            ]);

            return $parsed;

        } catch (\Exception $e) {
            Log::error('EMP request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'technical_message' => $e->getMessage(),
                'is_network_error' => true,
            ];
        }
    }

    /**
     * Parse XML response from EMP.
     */
    private function parseResponse(Response $response): array
    {
        if (!$response->successful()) {
            return [
                'status' => 'error',
                'http_status' => $response->status(),
                'technical_message' => 'HTTP error: ' . $response->status(),
            ];
        }

        $body = $response->body();
        
        try {
            $xml = new \SimpleXMLElement($body);
            return $this->xmlToArray($xml);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'technical_message' => 'Failed to parse XML response',
                'raw_response' => substr($body, 0, 1000),
            ];
        }
    }

    /**
     * Convert SimpleXMLElement to array.
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];
        
        foreach ($xml->children() as $key => $value) {
            $children = $value->children();
            if (count($children) > 0) {
                $result[$key] = $this->xmlToArray($value);
            } else {
                $result[$key] = (string) $value;
            }
        }
        
        return $result;
    }

    /**
     * Sanitize name for EMP API (remove special chars, limit length).
     */
    private function sanitizeName(string $name): string
    {
        // Remove non-ASCII characters except common European ones
        $name = preg_replace('/[^\p{L}\p{N}\s\-\'\.]/u', '', $name);
        
        // Limit to 35 characters (SEPA limit)
        return substr(trim($name), 0, 35);
    }

    /**
     * Verify webhook signature.
     */
    public function verifySignature(string $uniqueId, string $signature): bool
    {
        $expected = hash('sha1', $uniqueId . $this->password);
        
        return hash_equals($expected, $signature);
    }

    /**
     * Get terminal token.
     */
    public function getTerminalToken(): string
    {
        return $this->terminalToken;
    }
}
