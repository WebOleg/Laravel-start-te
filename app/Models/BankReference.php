<?php
/**
 * Bank reference model for caching bank information from iban.com API.
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankReference extends Model
{
    use HasFactory;
    
    public const RISK_LEVEL_LOW = 'low';
    public const RISK_LEVEL_MEDIUM = 'medium';
    public const RISK_LEVEL_HIGH = 'high';

    protected $fillable = [
        'country_iso',
        'bank_code',
        'bic',
        'bank_name',
        'branch',
        'address',
        'city',
        'zip',
        'sepa_sct',
        'sepa_sdd',
        'sepa_cor1',
        'sepa_b2b',
        'sepa_scc',
        'risk_level'
    ];

    protected $casts = [
        'sepa_sct' => 'boolean',
        'sepa_sdd' => 'boolean',
        'sepa_cor1' => 'boolean',
        'sepa_b2b' => 'boolean',
        'sepa_scc' => 'boolean',
    ];

    /**
     * @param string $countryIso
     * @param string $bankCode
     * @return ?self
     */
    public static function findByBankCode(string $countryIso, string $bankCode): ?self
    {
        return self::where('country_iso', strtoupper($countryIso))
            ->where('bank_code', $bankCode)
            ->first();
    }

    /**
     * @param string $bic
     * @return ?self
     */
    public static function findByBic(string $bic): ?self
    {
        return self::where('bic', strtoupper($bic))->first();
    }
}