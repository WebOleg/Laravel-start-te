<?php

namespace App\Traits;

use Illuminate\Support\Facades\Context;

trait WithLogContext
{
    /**
     * Call this at the very beginning of your job's handle() method.
     */
    protected function initLogContext(?string $acquirer = null): void
    {
        // Automatically grabs the short class name (e.g., 'ProcessBillingChunkJob')
        Context::add('job_type', class_basename(self::class));

        if ($acquirer) {
            Context::add('acquirer', $acquirer);
        }
    }
}
