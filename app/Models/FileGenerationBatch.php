<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileGenerationBatch extends Model
{
    protected $fillable = [
        'token',
        'admin_id',
        'source_file',
        's3_path_source',
        's3_path_result',
        'status',
        'target_amount',
        'tolerance',
        'achieved_amount',
        'total_input_rows',
        'eligible_rows',
        'selected_rows',
        'excluded_blacklist_rows',
        'excluded_billing_rows',
        'excluded_previously_used_rows',
        'error',
        'completed_at',
    ];

    protected $casts = [
        'target_amount'   => 'decimal:2',
        'tolerance'       => 'decimal:2',
        'achieved_amount' => 'decimal:2',
        'completed_at'    => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'admin_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(FileGenerationRecord::class, 'batch_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get all IBANs that have ever been used in ANY completed batch.
     *
     * @return \Illuminate\Support\Collection<string>
     */
    public static function allUsedIbans(): \Illuminate\Support\Collection
    {
        return FileGenerationRecord::query()
            ->whereHas('batch', fn ($q) => $q->where('status', 'completed'))
            ->pluck('iban')
            ->unique();
    }
}
