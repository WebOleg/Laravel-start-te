<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Monolog\Formatter\LineFormatter;
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

            // Set the Custom Formatter
            $format = "[%datetime%] %channel%.%level_name%: [instance:%extra.tether_instance_id%] [job:%extra.job_type%] [acquirer:%extra.acquirer%] %message% %context%\n";
            $dateFormat = "Y-m-d H:i:s";

            $formatter = new LineFormatter($format, $dateFormat, true, true);

            $handler->setFormatter($formatter);
        }
    }
}
