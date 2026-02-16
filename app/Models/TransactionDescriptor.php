<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use DateTimeInterface;

class TransactionDescriptor extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'descriptor_name',
        'descriptor_city',
        'descriptor_country',
        'is_default',
        'emp_account_id',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'year' => 'integer',
        'month' => 'integer',
    ];

    /**
     * Scope to find the specific rule for a given date.
     * Usage: TransactionDescriptor::specificFor(now())->first();
     */
    public function scopeSpecificFor(Builder $query, DateTimeInterface $date): void
    {
        $query->where('year', $date->format('Y'))
              ->where('month', $date->format('n'));
    }

    /**
     * Scope to find the default fallback.
     * Usage: TransactionDescriptor::defaultFallback()->first();
     */
    public function scopeDefaultFallback(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * Get the EMP account associated with this descriptor.
     */
    public function empAccount()
    {
        return $this->belongsTo(EmpAccount::class);
    }
}
