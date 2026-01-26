<?php

/**
 * Chargeback model for tracking EMP SDD chargeback events.
 * For SDD: one chargeback per transaction (unique by original_transaction_unique_id).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chargeback extends Model
{
    use HasFactory;

    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_API_SYNC = 'api_sync';

    public const TYPE_FIRST_CHARGEBACK = '1st chargeback';

    protected $fillable = [
        'billing_attempt_id',
        'debtor_id',
        'original_transaction_unique_id',
        'type',
        'reason_code',
        'reason_description',
        'chargeback_amount',
        'chargeback_currency',
        'post_date',
        'import_date',
        'source',
        'api_response',
    ];

    protected $casts = [
        'chargeback_amount' => 'decimal:2',
        'post_date' => 'date',
        'import_date' => 'date',
        'api_response' => 'array',
    ];

    public function billingAttempt(): BelongsTo
    {
        return $this->belongsTo(BillingAttempt::class);
    }

    public function debtor(): BelongsTo
    {
        return $this->belongsTo(Debtor::class);
    }

    public function isFirstChargeback(): bool
    {
        return strtolower($this->type) === self::TYPE_FIRST_CHARGEBACK;
    }
}
