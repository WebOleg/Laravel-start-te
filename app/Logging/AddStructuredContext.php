<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Illuminate\Support\Facades\Context;

class AddStructuredContext
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {

            // Add the Custom Processor
            $handler->pushProcessor(function (LogRecord|array $record) {
                $extra = [
                    'tether_instance_id' => config('app.tether_instance_id', 'unknown'),
                    'job_type'           => Context::get('job_type', 'none'),
                    'acquirer'           => Context::get('acquirer', 'none'),
                ];

                if ($record instanceof LogRecord) {
                    return $record->with(extra: array_merge($record->extra, $extra));
                }

                // Fallback for Monolog 2
                $record['extra'] = array_merge($record['extra'] ?? [], $extra);
                return $record;
            });
        }
    }
}
