<?php
/**
 * IBAN.com BAV (Bank Account Verification) API service.
 * Uses BAV v3 endpoint for name matching verification.
 */
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IbanBavService
{
    private const SUPPORTED_COUNTRIES = [
        'AT', 'BE', 'CY', 'DE', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PT', 'SI', 'SK',
    ];

    private const SCORE_MAP = [
        'yes' => 100,
        'partial' => 70,
        'unavailable' => 50,
        'no' => 20,
    ];

    private const RESULT_VERIFIED = 'verified';
    private const RESULT_LIKELY_VERIFIED = 'likely_verified';
    private const RESULT_INCONCLUSIVE = 'inconclusive';
    private const RESULT_MISMATCH = 'mismatch';
    private const RESULT_REJECTED = 'rejected';

    /** @var array<string> Valid error codes that indicate successful verification */
    private const VALID_RESPONSES = [
        'NAME_NOT_MATCH',
        'NAME_PARTIAL_MATCH',
    ];

    private string $apiKey;
    private string $apiUrl;
    private bool $mockMode;
    private IbanValidator $ibanValidator;

    public function __construct(IbanValidator $ibanValidator)
    {
        $this->apiKey = config('services.iban.api_key', '');
        $this->apiUrl = config('services.iban.bav_api_url', 'https://api.iban.com/clients/api/verify/v3/');
        $this->mockMode = config('services.iban.mock', true);
        $this->ibanValidator = $ibanValidator;
    }

    /**
     * @return array{success: bool, valid: bool, name_match: string, bic: ?string, vop_score: int, vop_result: string, error: ?string}
     */
    public function verify(string $iban, string $name): array
    {
        $normalizedIban = $this->ibanValidator->normalize($iban);
        $countryCode = substr($normalizedIban, 0, 2);

        Log::channel('bav')->info('IbanBavService: verify() called', [
            'iban' => $this->ibanValidator->mask($iban),
            'name' => $name,
            'country' => $countryCode,
            'mock_mode' => $this->mockMode,
        ]);

        if (!$this->isCountrySupported($countryCode)) {
            Log::channel('bav')->warning('IbanBavService: Country not supported for BAV', [
                'country' => $countryCode,
                'iban' => $this->ibanValidator->mask($iban),
                'supported_countries' => self::SUPPORTED_COUNTRIES,
            ]);

            return $this->buildResult(
                success: false,
                error: "Country {$countryCode} is not supported by BAV API"
            );
        }

        if ($this->mockMode) {
            Log::channel('bav')->info('IbanBavService: Using MOCK response', [
                'iban' => $this->ibanValidator->mask($iban),
            ]);
            return $this->getMockResponse($normalizedIban, $name);
        }

        return $this->callApi($normalizedIban, $name);
    }

    public function isCountrySupported(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::SUPPORTED_COUNTRIES, true);
    }

    public function getSupportedCountries(): array
    {
        return self::SUPPORTED_COUNTRIES;
    }

    private function callApi(string $iban, string $name): array
    {
        try {
            Log::channel('bav')->info('IbanBavService: Making BAV API request', [
                'url' => $this->apiUrl,
                'iban' => $this->ibanValidator->mask($iban),
                'name' => $name,
                'has_api_key' => !empty($this->apiKey),
            ]);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl, [
                'IBAN' => $iban,
                'name' => $name,
            ]);

            $data = $response->json();
            $errorCode = $data['error'] ?? '';
            $querySuccess = $data['query']['success'] ?? false;

            Log::channel('bav')->info('IbanBavService: BAV API response received', [
                'iban_masked' => $this->ibanValidator->mask($iban),
                'status' => $response->status(),
                'success' => $querySuccess,
                'name_match' => $data['result']['name_match'] ?? null,
                'error' => $errorCode,
                'valid' => $data['result']['valid'] ?? null,
                'bic' => $data['result']['bic'] ?? null,
            ]);

            // Check if query was successful
            if (!$querySuccess) {
                // Real error - not a valid response
                return $this->buildResult(success: false, error: $errorCode ?: 'BAV verification failed');
            }

            // Query successful - even if error contains NAME_NOT_MATCH etc, it's a valid result
            $nameMatch = $data['result']['name_match'] ?? 'unavailable';
            $valid = !empty($data['result']['valid']);
            $bic = $data['result']['bic'] ?? null;

            // If we got a name_match result, the verification was successful
            if (in_array($nameMatch, ['yes', 'partial', 'no'], true)) {
                return $this->buildResult(
                    success: true,
                    valid: true,
                    nameMatch: $nameMatch,
                    bic: $bic
                );
            }

            // Verification succeeded but bank doesn't support name matching
            return $this->buildResult(
                success: true,
                valid: $valid,
                nameMatch: 'unavailable',
                bic: $bic
            );

        } catch (\Exception $e) {
            Log::channel('bav')->error('BAV API error', [
                'iban_masked' => $this->ibanValidator->mask($iban),
                'error' => $e->getMessage(),
            ]);

            return $this->buildResult(success: false, error: $e->getMessage());
        }
    }

    private function getMockResponse(string $iban, string $name): array
    {
        $lastDigit = (int) substr($iban, -1);

        $result = match (true) {
            $lastDigit === 0 => $this->buildResult(success: true, valid: true, nameMatch: 'yes', bic: 'DEUTDEFF'),
            $lastDigit === 1 => $this->buildResult(success: true, valid: true, nameMatch: 'partial', bic: 'COBADEFF'),
            $lastDigit === 2 => $this->buildResult(success: true, valid: true, nameMatch: 'no', bic: 'BNPAFRPP'),
            $lastDigit === 9 => $this->buildResult(success: true, valid: false, nameMatch: 'unavailable', bic: null),
            default => $this->buildResult(success: true, valid: true, nameMatch: 'yes', bic: 'ABNANL2A'),
        };

        Log::channel('bav')->info('IbanBavService: Mock response generated', [
            'iban' => $this->ibanValidator->mask($iban),
            'name' => $name,
            'name_match' => $result['name_match'],
            'valid' => $result['valid'],
            'bic' => $result['bic'],
            'vop_score' => $result['vop_score'],
            'vop_result' => $result['vop_result'],
        ]);

        return $result;
    }

    private function calculateVopScore(string $nameMatch, bool $valid): int
    {
        if (!$valid) {
            return 0;
        }

        return self::SCORE_MAP[$nameMatch] ?? 50;
    }

    private function determineVopResult(int $score): string
    {
        return match (true) {
            $score >= 90 => self::RESULT_VERIFIED,
            $score >= 70 => self::RESULT_LIKELY_VERIFIED,
            $score >= 50 => self::RESULT_INCONCLUSIVE,
            $score >= 20 => self::RESULT_MISMATCH,
            default => self::RESULT_REJECTED,
        };
    }

    private function buildResult(
        bool $success,
        bool $valid = false,
        string $nameMatch = 'unavailable',
        ?string $bic = null,
        ?string $error = null
    ): array {
        $vopScore = $this->calculateVopScore($nameMatch, $valid);

        return [
            'success' => $success,
            'valid' => $valid,
            'name_match' => $nameMatch,
            'bic' => $bic,
            'vop_score' => $vopScore,
            'vop_result' => $this->determineVopResult($vopScore),
            'error' => $error,
        ];
    }
}
