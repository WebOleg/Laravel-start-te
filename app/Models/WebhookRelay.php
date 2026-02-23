<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WebhookRelay extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'target',
    ];

    /**
     * Get the EMP accounts associated with this relay.
     *
     * @return BelongsToMany
     */
    public function empAccounts(): BelongsToMany
    {
        return $this->belongsToMany(
            EmpAccount::class,
            'emp_account_webhook_relay',
            'webhook_relay_id',
            'emp_account_id'
        )->withTimestamps();
    }
}
