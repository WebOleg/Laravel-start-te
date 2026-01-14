<?php

namespace App\Console\Commands;

use App\Services\Emp\EmpChargebackService;
use Illuminate\Console\Command;

class ChargebackCodeUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'emp:fetch-chargeback-codes
                            {unique-id? : Specific unique_id(s) to process}
                            {--all : Process all chargebacks with fille dor empty reason codes}
                            {--empty : Process all chargebacks missing reason codes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to fetch and update chargeback codes from emerchantpay';

    public function __construct(private EmpChargebackService $chargebackService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $uniqueId = $this->argument('unique-id') ?? null;
        $processAll = $this->option('all');
        $processEmptyReason = $this->option('empty');

        // Enure at least one option is provided
        if(!$uniqueId && !$processAll && !$processEmptyReason){
            $this->error("Please provide at least one of the following options: {unique-id}, --all, --empty");
            $this->info("Usage examples:");
            $this->info("  php artisan emp:fetch-chargeback-codes {unique-id}");
            $this->info("  php artisan emp:fetch-chargeback-codes --all");
            $this->info("  php artisan emp:fetch-chargeback-codes --empty");
            return Command::FAILURE;
        }

        $this->info("Starting chargeback code update process...");
        if($uniqueId != null){ 
            $this->processChargebackByUniqueId($uniqueId);
        }

        if($processAll == true){
            $response = $this->chargebackService->processBulkChargebackDetail(true);
            
        }

        if($processEmptyReason == true){
            $response = $this->chargebackService->processBulkChargebackDetail(false);
            
        }

        return Command::SUCCESS;
    }

    private function processChargebackByUniqueId(string $uniqueId): void
    {
        $this->info("Prcessing Unique ID: {$uniqueId}");
        $response = $this->chargebackService->processChargebackDetail($uniqueId);
        
        $this->info("Resopnse: " . print_r($response, true));
    }
}
