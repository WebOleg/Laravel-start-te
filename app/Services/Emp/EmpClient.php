<?php

/**
 * HTTP client for emerchantpay Genesis API.
 * Handles authentication, XML building, and request/response processing.
 * Supports multiple EMP accounts via EmpAccount model.
 */

namespace App\Services\Emp;

use App\Models\EmpAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use RuntimeException;

class EmpClient
{
    private ?string $endpoint = null;
    private ?string $username = null;
    private ?string $password = null;
    private ?string $terminalToken = null;
    private ?int $empAccountId = null;
    private ?string $empAccountName = null;
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $initialized = false;

    /**
     * Lazy load configuration on first use.
     * Prioritizes active EmpAccount, falls back to .env config.
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->timeout = config('services.emp.timeout', 30);
        $this->connectTimeout = config('services.emp.connect_timeout', 10);

        // Try to load from active EmpAccount first
        $activeAccount = EmpAccount::getActive();
        
        if ($activeAccount) {
            $this->endpoint = $activeAccount->endpoint;
            $this->username = $activeAccount->username;
            $this->password = $activeAccount->password;
            $this->terminalToken = $activeAccount->terminal_token;
            $this->empAccountId = $activeAccount->id;
            $this->empAccountName = $activeAccount->name;
        } else {
            // Fallback to .env config
            $this->endpoint = config('services.emp.endpoint');
            $this->username = config('services.emp.username');
            $this->password = config('services.emp.password');
            $this->terminalToken = config('services.emp.terminal_token');
        }

        if (!$this->username || !$this->password || !$this->terminalToken) {
            throw new RuntimeException('EMP credentials not configured. Set active EmpAccount or configure .env');
        }

        $this->initialized = true;
    }

    /**
     * Force re-initialization (useful when switching accounts).
     */
    public function reinitialize(): void
    {
        $this->initialized = false;
        $this->initialize();
    }

    /**
     * Get current EMP account ID.
     */
    public function getEmpAccountId(): ?int
    {
        $this->initialize();
        return $this->empAccountId;
    }

    /**
     * Get current EMP account name.
     */
    public function getEmpAccountName(): ?string
    {
        $this->initialize();
        return $this->empAccountName;
    }

    /**
     * Send SDD Sale (SEPA Direct Debit) transaction.
     */
    public function sddSale(array $data): array
    {
        $this->initialize();
        $xml = $this->buildSddSaleXml($data);
        
        return $this->sendRequest('/process/' . $this->terminalToken, $xml);
    }

    /**
     * Reconcile a transaction by unique_id.
     */
    public function reconcile(string $uniqueId): array
    {
        $this->initialize();
        $xml = $this->buildReconcileXml($uniqueId);
        
        return $this->sendRequest('/reconcile/' . $this->terminalToken, $xml);
    }

    /**
     * Get transactions by date range (for EMP Refresh).
     */
    public function getTransactionsByDate(string $startDate, string $endDate, int $page = 1): array
    {
        $this->initialize();
        $xml = $this->buildGetByDateXml($startDate, $endDate, $page);
        
        return $this->sendRequest('/reconcile/by_date/' . $this->terminalToken, $xml);
    }

    /**
     * Get chargebacks by import date (for chargeback sync).
     * Uses import_date instead of start_date/end_date for SDD transactions.
     */
    public function getChargebacksByImportDate(string $importDate, int $page = 1, int $perPage = 100): array
    {
        $this->initialize();
        $xml = $this->buildChargebacksByDateXml($importDate, $page, $perPage);
        
        return $this->sendRequest('/chargebacks/by_date', $xml);
    }

    /**
     * Build XML for get transactions by date request.
     */
    private function buildGetByDateXml(string $startDate, string $endDate, int $page): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><reconcile/>');
        $xml->addChild('start_date', $startDate);
        $xml->addChild('end_date', $endDate);
        $xml->addChild('page', (string) $page);
        
        return $xml->asXML();
    }

    /**
     * Build XML for chargebacks by date request.
     */
    private function buildChargebacksByDateXml(string $importDate, int $page, int $perPage): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><chargeback_request/>');
        $xml->addChild('import_date', $importDate);
        $xml->addChild('page', (string) $page);
        $xml->addChild('per_page', (string) $perPage);
        
        return $xml->asXML();
    }

    /**
     * Build XML for SDD Sale transaction.
     * Note: customer_email intentionally not sent to EMP for privacy reasons.
     */
    private function buildSddSaleXml(array $data): string
    {
        $amount = (int) round($data['amount'] * 100);
        
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><payment_transaction/>');
        
        $xml->addChild('transaction_type', 'sdd_sale');
        $xml->addChild('transaction_id', $data['transaction_id']);
        $xml->addChild('usage', $data['usage'] ?? 'Payment');
        $xml->addChild('remote_ip', $data['remote_ip'] ?? request()->ip() ?? '127.0.0.1');
        $xml->addChild('amount', (string) $amount);
        $xml->addChild('currency', $data['currency'] ?? 'EUR');
        $xml->addChild('iban', $data['iban']);
        
        if (!empty($data['bic'])) {
            $xml->addChild('bic', $data['bic']);
        }
        
        if (!empty($data['notification_url'])) {
            $xml->addChild('notification_url', $data['notification_url']);
        }
        
        if (!empty($data['return_success_url'])) {
            $xml->addChild('return_success_url', $data['return_success_url']);
            $xml->addChild('return_failure_url', $data['return_failure_url'] ?? $data['return_success_url']);
            $xml->addChild('return_cancel_url', $data['return_cancel_url'] ?? $data['return_success_url']);
        }
        
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
     * Build XML for chargeback details request.
     */
    public function buildChargebackDetailXml(string $uniqueId): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><chargeback_request/>');
        $xml->addChild('original_transaction_unique_id', $uniqueId);

        return $xml->asXML();
    }

    /**
     * Send HTTP request to EMP API.
     */
    public function sendRequest(string $path, string $xml): array
    {
        $this->initialize();
        $url = 'https://' . $this->endpoint . $path;

        Log::debug('EMP request', [
            'url' => $url,
            'xml' => $xml,
            'emp_account' => $this->empAccountName,
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
                'emp_account' => $this->empAccountName,
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
     * Convert SimpleXMLElement to array with attributes support.
     */
    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $result = [];
        
        foreach ($xml->attributes() as $attrName => $attrValue) {
            $result['@' . $attrName] = (string) $attrValue;
        }
        
        $children = [];
        foreach ($xml->children() as $key => $value) {
            $children[$key][] = $value;
        }
        
        foreach ($children as $key => $items) {
            if (count($items) === 1) {
                $child = $items[0];
                if ($child->count() > 0 || $child->attributes()->count() > 0) {
                    $result[$key] = $this->xmlToArray($child);
                } else {
                    $result[$key] = (string) $child;
                }
            } else {
                $result[$key] = [];
                foreach ($items as $child) {
                    if ($child->count() > 0 || $child->attributes()->count() > 0) {
                        $result[$key][] = $this->xmlToArray($child);
                    } else {
                        $result[$key][] = (string) $child;
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Sanitize name for EMP API (remove special chars, limit length).
     */
    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}\s\-\'\.]/u', '', $name);
        
        return substr(trim($name), 0, 35);
    }

    /**
     * Verify webhook signature.
     */
    public function verifySignature(string $uniqueId, string $signature): bool
    {
        $this->initialize();
        $expected = hash('sha1', $uniqueId . $this->password);
        
        return hash_equals($expected, $signature);
    }

    /**
     * Get terminal token.
     */
    public function getTerminalToken(): string
    {
        $this->initialize();
        return $this->terminalToken;
    }
}
