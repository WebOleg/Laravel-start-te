<?php

/**
 * IBAN.com API V4 integration service.
 * 
 * Provides bank account validation and bank information lookup via iban.com API.
 * Uses local database cache (bank_references) to reduce API calls.
 */

namespace App\Services;

use App\Models\BankReference;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class IbanApiService
{
    private const CACHE_PREFIX = 'iban_api:';
    private const CACHE_TTL = 86400;
    private const TIMEOUT = 10;
    private const RETRY_TIMES = 2;
    private const RETRY_DELAY = 100;

    private string $apiKey;
    private string $apiUrl;
    private bool $mockMode;

    public function __construct()
    {
        $this->apiKey = config('services.iban.api_key', '');
        $this->apiUrl = config('services.iban.api_url', 'https://api.iban.com/clients/api/v4/iban/');
        $this->mockMode = config('services.iban.mock', true);
    }

    /**
     * Verify IBAN and retrieve bank information.
     *
     * @param string $iban
     * @param bool $skipLocalCache Skip local DB lookup (force API call)
     * @return array{success: bool, bank_data: ?array, sepa_data: ?array, validations: ?array, error: ?string, cached: bool, source: string}
     */
    public function verify(string $iban, bool $skipLocalCache = false): array
    {
        $normalized = $this->normalize($iban);
        $countryIso = substr($normalized, 0, 2);
        $bankCode = $this->extractBankCode($normalized, $countryIso);

        if (!$skipLocalCache && $bankCode) {
            $local = $this->findInLocalCache($countryIso, $bankCode);
            if ($local) {
                return $local;
            }
        }

        $cacheKey = self::CACHE_PREFIX . hash('sha256', $normalized);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return array_merge($cached, ['cached' => true, 'source' => 'memory']);
        }

        $result = $this->mockMode 
            ? $this->mockResponse($normalized)
            : $this->callApi($normalized);

        if ($result['success']) {
            Cache::put($cacheKey, $result, self::CACHE_TTL);
            $this->saveToLocalCache($result, $countryIso);
        }

        return array_merge($result, ['cached' => false, 'source' => 'api']);
    }

    /**
     * @param string $iban
     * @return ?string
     */
    public function getBankName(string $iban): ?string
    {
        return $this->verify($iban)['bank_data']['bank'] ?? null;
    }

    /**
     * @param string $iban
     * @return ?string
     */
    public function getBic(string $iban): ?string
    {
        return $this->verify($iban)['bank_data']['bic'] ?? null;
    }

    /**
     * @param string $iban
     * @return bool
     */
    public function isValid(string $iban): bool
    {
        $result = $this->verify($iban);
        if (!$result['success']) {
            return false;
        }
        $code = $result['validations']['iban']['code'] ?? 0;
        return $code === 1 || $code === '001';
    }

    /**
     * @param string $iban
     * @return bool
     */
    public function supportsSepaSdd(string $iban): bool
    {
        $sepa = $this->verify($iban)['sepa_data'] ?? [];
        return strtoupper($sepa['SDD'] ?? '') === 'YES';
    }

    /**
     * @param string $countryIso
     * @param string $bankCode
     * @return ?array
     */
    private function findInLocalCache(string $countryIso, string $bankCode): ?array
    {
        $ref = BankReference::findByBankCode($countryIso, $bankCode);
        if (!$ref) {
            return null;
        }

        return [
            'success' => true,
            'bank_data' => [
                'bic' => $ref->bic,
                'bank' => $ref->bank_name,
                'branch' => $ref->branch,
                'address' => $ref->address,
                'city' => $ref->city,
                'zip' => $ref->zip,
                'country_iso' => $ref->country_iso,
                'bank_code' => $bankCode,
            ],
            'sepa_data' => [
                'SCT' => $ref->sepa_sct ? 'YES' : 'NO',
                'SDD' => $ref->sepa_sdd ? 'YES' : 'NO',
                'COR1' => $ref->sepa_cor1 ? 'YES' : 'NO',
                'B2B' => $ref->sepa_b2b ? 'YES' : 'NO',
                'SCC' => $ref->sepa_scc ? 'YES' : 'NO',
            ],
            'validations' => null,
            'error' => null,
            'cached' => true,
            'source' => 'database',
        ];
    }

    /**
     * @param array $result
     * @param string $countryIso
     * @return void
     */
    private function saveToLocalCache(array $result, string $countryIso): void
    {
        $bankData = $result['bank_data'] ?? [];
        $sepaData = $result['sepa_data'] ?? [];
        $bankCode = $bankData['bank_code'] ?? null;

        if (!$bankCode || !($bankData['bank'] ?? null)) {
            return;
        }

        try {
            BankReference::updateOrCreate(
                ['country_iso' => $countryIso, 'bank_code' => $bankCode],
                [
                    'bic' => $bankData['bic'] ?? null,
                    'bank_name' => $bankData['bank'],
                    'branch' => $bankData['branch'] ?? null,
                    'address' => $bankData['address'] ?? null,
                    'city' => $bankData['city'] ?? null,
                    'zip' => $bankData['zip'] ?? null,
                    'sepa_sct' => strtoupper($sepaData['SCT'] ?? '') === 'YES',
                    'sepa_sdd' => strtoupper($sepaData['SDD'] ?? '') === 'YES',
                    'sepa_cor1' => strtoupper($sepaData['COR1'] ?? '') === 'YES',
                    'sepa_b2b' => strtoupper($sepaData['B2B'] ?? '') === 'YES',
                    'sepa_scc' => strtoupper($sepaData['SCC'] ?? '') === 'YES',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('IbanApiService: failed to save bank reference', [
                'error' => $e->getMessage(),
                'country' => $countryIso,
                'bank_code' => $bankCode,
            ]);
        }
    }

    /**
     * @param string $iban
     * @param string $countryIso
     * @return ?string
     */
    private function extractBankCode(string $iban, string $countryIso): ?string
    {
        $lengths = [
            'DE' => 8, 'AT' => 5, 'BE' => 3, 'ES' => 4, 'FR' => 5,
            'IT' => 5, 'NL' => 4, 'PT' => 4, 'PL' => 8, 'GB' => 6,
        ];

        $length = $lengths[$countryIso] ?? null;
        if (!$length || strlen($iban) < 4 + $length) {
            return null;
        }

        return substr($iban, 4, $length);
    }

    /**
     * @param string $iban
     * @return array{success: bool, bank_data: ?array, sepa_data: ?array, validations: ?array, error: ?string}
     */
    private function callApi(string $iban): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->retry(self::RETRY_TIMES, self::RETRY_DELAY)
                ->asForm()
                ->post($this->apiUrl, [
                    'iban' => $iban,
                    'api_key' => $this->apiKey,
                    'format' => 'json',
                ]);

            if (!$response->successful()) {
                Log::warning('IbanApiService: request failed', [
                    'status' => $response->status(),
                    'iban' => $this->mask($iban),
                ]);
                return $this->errorResult('HTTP ' . $response->status());
            }

            return $this->parseResponse($response->json());

        } catch (ConnectionException $e) {
            Log::error('IbanApiService: connection failed', ['error' => $e->getMessage()]);
            return $this->errorResult('Connection failed');
        } catch (\Throwable $e) {
            Log::error('IbanApiService: unexpected error', ['error' => $e->getMessage()]);
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * @param array $data
     * @return array{success: bool, bank_data: ?array, sepa_data: ?array, validations: ?array, error: ?string}
     */
    private function parseResponse(array $data): array
    {
        $errors = $data['errors'] ?? [];
        if (!empty($errors)) {
            $error = is_array($errors) ? ($errors[0]['message'] ?? 'Unknown error') : $errors;
            return $this->errorResult($error);
        }

        return [
            'success' => true,
            'bank_data' => $data['bank_data'] ?? null,
            'sepa_data' => $data['sepa_data'] ?? null,
            'validations' => $data['validations'] ?? null,
            'error' => null,
        ];
    }

    /**
     * @param string $iban
     * @return array{success: bool, bank_data: ?array, sepa_data: ?array, validations: ?array, error: ?string}
     */
    private function mockResponse(string $iban): array
    {
        $country = substr($iban, 0, 2);
        $banks = [
            'DE' => ['bank' => 'Deutsche Bank', 'bic' => 'DEUTDEFF'],
            'ES' => ['bank' => 'Banco Santander', 'bic' => 'BSCHESMM'],
            'FR' => ['bank' => 'BNP Paribas', 'bic' => 'BNPAFRPP'],
            'IT' => ['bank' => 'UniCredit', 'bic' => 'UNCRITMM'],
            'NL' => ['bank' => 'ING Bank', 'bic' => 'INGBNL2A'],
            'GB' => ['bank' => 'Barclays Bank', 'bic' => 'BARCGB22'],
            'AT' => ['bank' => 'Erste Bank', 'bic' => 'GIBAATWW'],
            'BE' => ['bank' => 'KBC Bank', 'bic' => 'KREDBEBB'],
            'PT' => ['bank' => 'Millennium BCP', 'bic' => 'BCOMPTPL'],
            'PL' => ['bank' => 'PKO Bank', 'bic' => 'BPKOPLPW'],
        ];

        $bankInfo = $banks[$country] ?? ['bank' => 'Mock Bank', 'bic' => 'MOCKXX00'];
        $bankCode = $this->extractBankCode($iban, $country) ?? substr($iban, 4, 8);

        return [
            'success' => true,
            'bank_data' => [
                'bic' => $bankInfo['bic'],
                'bank' => $bankInfo['bank'],
                'branch' => '',
                'address' => '',
                'city' => '',
                'zip' => '',
                'country_iso' => $country,
                'account' => substr($iban, 4),
                'bank_code' => $bankCode,
            ],
            'sepa_data' => [
                'SCT' => 'YES',
                'SDD' => 'YES',
                'COR1' => 'YES',
                'B2B' => 'NO',
                'SCC' => 'NO',
            ],
            'validations' => [
                'chars' => ['code' => '006', 'message' => 'IBAN does not contain illegal characters'],
                'iban' => ['code' => '001', 'message' => 'IBAN Check digit is correct'],
                'account' => ['code' => '002', 'message' => 'Account Number check digit is correct'],
                'structure' => ['code' => '005', 'message' => 'IBAN structure is correct'],
                'length' => ['code' => '003', 'message' => 'IBAN Length is correct'],
                'country_support' => ['code' => '007', 'message' => 'Country supports IBAN standard'],
            ],
            'error' => null,
        ];
    }

    /**
     * @param string $message
     * @return array{success: bool, bank_data: null, sepa_data: null, validations: null, error: string}
     */
    private function errorResult(string $message): array
    {
        return [
            'success' => false,
            'bank_data' => null,
            'sepa_data' => null,
            'validations' => null,
            'error' => $message,
        ];
    }

    /**
     * @param string $iban
     * @return string
     */
    private function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    /**
     * @param string $iban
     * @return string
     */
    private function mask(string $iban): string
    {
        $n = $this->normalize($iban);
        return strlen($n) >= 8 ? substr($n, 0, 4) . '****' . substr($n, -4) : '****';
    }
}
