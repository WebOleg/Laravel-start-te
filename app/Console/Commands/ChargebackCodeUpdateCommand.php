<?php

namespace App\Console\Commands;

use App\Services\Emp\EmpChargebackService;
use Illuminate\Console\Command;

class ChargebackCodeUpdateCommand extends Command
{
    private EmpChargebackService $chargebackService;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emp:fetch-chargeback-codes
                            {unique-id? : Specific unique_id(s) to process}
                            {--all : Process all chargebacks with filled or empty reason codes}
                            {--empty : Process all chargebacks missing reason codes}
                            {--empty-reason-messages : Process all chargebacks missing reason descriptions (chargeback_reason_description)}
                            {--chunk= : Limit the number of records to process (only for --all, --empty, and --empty-reason-messages)}
                            {--from= : Start offset (skip this many records)}
                            {--to= : End offset (process up to this record number)}
                            {--dry-run : Show what would be processed without making actual changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to fetch and update chargeback codes from emerchantpay';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->chargebackService = app(EmpChargebackService::class);

        $uniqueId = $this->argument('unique-id');
        $processAll = $this->option('all');
        $processEmptyReason = $this->option('empty');
        $processEmptyReasonMessages = $this->option('empty-reason-messages');
        $limit = $this->option('chunk');
        $from = $this->option('from');
        $to = $this->option('to');
        $dryRun = $this->option('dry-run');

        // Ensure at least one option is provided
        if (!$uniqueId && !$processAll && !$processEmptyReason && !$processEmptyReasonMessages) {
            $this->error("Please provide at least one of the following options: {unique-id}, --all, --empty, --empty-reason-messages");
            $this->info("Examples: emp:fetch-chargeback-codes {unique-id} | --all | --empty | --empty-reason-messages [--chunk=N] [--from=N] [--to=N] [--dry-run]");
            return Command::FAILURE;
        }

        // Ensure options are mutually exclusive
        if ($uniqueId && ($processAll || $processEmptyReason || $processEmptyReasonMessages)) {
            $this->error("Cannot combine unique-id with --all, --empty, or --empty-reason-messages options");
            return Command::FAILURE;
        }

        $bulkOptionsCount = (int) $processAll + (int) $processEmptyReason + (int) $processEmptyReasonMessages;
        if ($bulkOptionsCount > 1) {
            $this->error("Cannot combine --all, --empty, and --empty-reason-messages options together");
            return Command::FAILURE;
        }

        // Validate --from and --to options
        if ($from !== null) {
            if ($uniqueId) {
                $this->error("Cannot use --from with unique-id argument");
                return Command::FAILURE;
            }
            if (!is_numeric($from) || $from < 0) {
                $this->error("--from must be a non-negative number");
                return Command::FAILURE;
            }
            $from = (int) $from;
        }

        if ($to !== null) {
            if ($uniqueId) {
                $this->error("Cannot use --to with unique-id argument");
                return Command::FAILURE;
            }
            if (!is_numeric($to) || $to <= 0) {
                $this->error("--to must be a positive number");
                return Command::FAILURE;
            }
            $to = (int) $to;
        }

        if ($from !== null && $to !== null && $from >= $to) {
            $this->error("--from must be less than --to");
            return Command::FAILURE;
        }

        // Validate limit option
        if ($limit !== null) {
            if ($uniqueId) {
                $this->error("Cannot use --chunk with unique-id argument");
                return Command::FAILURE;
            }

            if (!is_numeric($limit) || $limit <= 0) {
                $this->error("Chunk must be a positive number");
                return Command::FAILURE;
            }

            $limit = (int) $limit;
        }

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No actual changes will be made");
        }

        $this->info("Starting chargeback code update process...");

        // Process single unique ID
        if ($uniqueId) {
            return $this->processChargebackByUniqueId($uniqueId, $dryRun);
        }

        // Process bulk - all chargebacks
        if ($processAll) {
            return $this->processBulkChargebacks('all', $limit, $from, $to, $dryRun);
        }

        // Process bulk - empty reason codes only
        if ($processEmptyReason) {
            return $this->processBulkChargebacks('empty', $limit, $from, $to, $dryRun);
        }

        // Process bulk - empty reason descriptions only
        if ($processEmptyReasonMessages) {
            return $this->processBulkChargebacks('empty-reason-messages', $limit, $from, $to, $dryRun);
        }

        return Command::SUCCESS;
    }

    private function processChargebackByUniqueId(string $uniqueId, bool $dryRun = false): int
    {
        $this->info("Processing Unique ID: {$uniqueId}");

        if ($dryRun) {
            $this->warn("[DRY RUN] Would process unique ID: {$uniqueId}");
            return Command::SUCCESS;
        }

        $this->newLine();

        // Print table header
        $this->line(sprintf(
            "%-40s | %-15s | %s",
            'Unique ID',
            'Code',
            'Message/Details'
        ));
        $this->line(str_repeat('-', 120));

        $response = $this->chargebackService->processChargebackDetail($uniqueId);

        if (!$response['success']) {
            $errorCode = $response['code'] ?? 'N/A';
            $errorMessage = $response['error'] ?? 'Unknown error';

            // Truncate message if too long
            if (strlen($errorMessage) > 50) {
                $errorMessage = substr($errorMessage, 0, 47) . '...';
            }

            $this->line(sprintf(
                "%s | %s | %s",
                str_pad($uniqueId, 40),
                str_pad($errorCode, 15),
                $errorMessage
            ));

            return Command::FAILURE;
        }

        $reasonCode = $response['data']['reason_code'] ?? 'N/A';
        $reasonDescription = $response['data']['reason_description'] ?? 'N/A';

        // Truncate message if too long
        if (strlen($reasonDescription) > 50) {
            $reasonDescription = substr($reasonDescription, 0, 47) . '...';
        }

        $this->line(sprintf(
            "%s | %s | %s",
            str_pad($uniqueId, 40),
            str_pad($reasonCode, 15),
            $reasonDescription
        ));

        return Command::SUCCESS;
    }

    private function processBulkChargebacks(string $mode, ?int $limit = null, ?int $from = null, ?int $to = null, bool $dryRun = false): int
    {
        $typeLabels = [
            'all'                   => 'all chargebacks',
            'empty'                 => 'chargebacks with empty reason codes',
            'empty-reason-messages' => 'chargebacks with empty reason descriptions',
        ];

        $type = $typeLabels[$mode] ?? $mode;
        $limitText = $limit ? " (chunk: {$limit})" : '';
        $rangeText = ($from !== null || $to !== null) ? " (range: " . ($from ?? 0) . " to " . ($to ?? 'end') . ")" : '';
        $dryRunText = $dryRun ? ' [DRY RUN MODE]' : '';
        $this->info("Processing {$type}{$limitText}{$rangeText}{$dryRunText}...");

        $this->newLine();

        // Print table header
        $this->line(sprintf(
            "%-40s | %-15s | %s",
            'Unique ID',
            'Code',
            'Message/Details'
        ));
        $this->line(str_repeat('-', 120));

        // Process with real-time callback
        $results = $this->chargebackService->processBulkChargebackDetail($mode, function ($detail) {
            $uniqueId = str_pad($detail['unique_id'], 40);
            $code = str_pad($detail['code'] ?? 'N/A', 15);
            $message = $detail['message'] ?? 'N/A';

            // Truncate message if too long
            if (strlen($message) > 50) {
                $message = substr($message, 0, 47) . '...';
            }

            $this->line(sprintf(
                "%s | %s | %s",
                $uniqueId,
                $code,
                $message
            ));
        }, $limit, $from, $to, $dryRun);

        // Display summary
        $this->newLine();
        $this->line(str_repeat('=', 120));
        $this->info("Processing Summary:");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total', $results['total']],
                ['Processed', $results['processed']],
                ['Successful', $results['successful']],
                ['Failed', $results['failed']],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn("DRY RUN completed - No actual API calls or database updates were made");
        }

        return $results['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
