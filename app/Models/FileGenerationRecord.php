<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileGenerationRecord extends Model
{
    protected $fillable = [
        'batch_id',
        'iban',
        'amount',
        'source_row_index',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FileGenerationBatch::class, 'batch_id');
    }
}
