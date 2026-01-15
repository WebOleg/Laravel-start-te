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
                            {--chunk= : Limit the number of records to process (only for --all and --empty)}
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
        $limit = $this->option('chunk');
        $dryRun = $this->option('dry-run');

        // Ensure at least one option is provided
        if (!$uniqueId && !$processAll && !$processEmptyReason) {
            $this->error("Please provide at least one of the following options: {unique-id}, --all, --empty");
            $this->info("Examples: emp:fetch-chargeback-codes {unique-id} | --all | --empty [--chunk=N] [--dry-run]");
            return Command::FAILURE;
        }

        // Ensure options are mutually exclusive
        if ($uniqueId && ($processAll || $processEmptyReason)) {
            $this->error("Cannot combine unique-id with --all or --empty options");
            return Command::FAILURE;
        }

        if ($processAll && $processEmptyReason) {
            $this->error("Cannot use both --all and --empty options together");
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
            return $this->processBulkChargebacks(true, $limit, $dryRun);
        }

        // Process bulk - empty reason codes only
        if ($processEmptyReason) {
            return $this->processBulkChargebacks(false, $limit, $dryRun);
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

    private function processBulkChargebacks(bool $processAll, ?int $limit = null, bool $dryRun = false): int
    {
        $type = $processAll ? 'all chargebacks' : 'chargebacks with empty reason codes';
        $limitText = $limit ? " (chunk: {$limit})" : '';
        $dryRunText = $dryRun ? ' [DRY RUN MODE]' : '';
        $this->info("Processing {$type}{$limitText}{$dryRunText}...");
        
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
        $results = $this->chargebackService->processBulkChargebackDetail($processAll, function ($detail) {
            $status = $detail['success'] ? '<info>✓</info>' : '<error>✗</error>';
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
        }, $limit, $dryRun);
        
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
