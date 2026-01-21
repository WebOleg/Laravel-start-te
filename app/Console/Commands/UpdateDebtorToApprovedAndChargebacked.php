<?php

namespace App\Console\Commands;

use App\Models\Debtor;
use App\Models\BillingAttempt;
use Illuminate\Console\Command;

class UpdateDebtorToApprovedAndChargebacked extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:debtor-approved-and-chargebacked
        {--only-approved : Only update recovered -> approved if an approved billing attempt exists}
        {--only-chargebacked : Only update failed -> chargebacked if a chargebacked billing attempt exists}
        {--dry-run : Show counts only, do not update}
        {--limit= : Limit number of debtors updated per status}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update old debtor status: recovered -> approved and failed -> chargebacked';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        // Approved
        $approvedQuery = Debtor::where('status', Debtor::STATUS_RECOVERED);

        if ($this->option('only-approved')) {
            $approvedQuery->whereHas('billingAttempts', function ($q) {
                $q->where('status', BillingAttempt::STATUS_APPROVED);
            });
        }

        $approvedCount = $approvedQuery->count();
        $approvedIds = $this->pluckLimitedIds($approvedQuery, $limit);

        if ($dryRun) {
            $this->info("Approved (dry-run): would update " . count($approvedIds) . " of {$approvedCount} recovered debtors.");
        } else {
            $approvedUpdated = $this->updateByIds($approvedIds, Debtor::STATUS_APPROVED);
            $this->info("Approved: updated {$approvedUpdated} of {$approvedCount} recovered debtors.");
        }

        // Chargebacked
        $chargebackQuery = Debtor::where('status', Debtor::STATUS_FAILED);

        if ($this->option('only-chargebacked')) {
            $chargebackQuery->whereHas('billingAttempts', function ($q) {
                $q->where('status', BillingAttempt::STATUS_CHARGEBACKED);
            });
        }

        $chargebackCount = $chargebackQuery->count();
        $chargebackIds = $this->pluckLimitedIds($chargebackQuery, $limit);

        if ($dryRun) {
            $this->info("Chargebacked (dry-run): would update " . count($chargebackIds) . " of {$chargebackCount} failed debtors.");
        } else {
            $chargebackUpdated = $this->updateByIds($chargebackIds, Debtor::STATUS_CHARGEBACKED);
            $this->info("Chargebacked: updated {$chargebackUpdated} of {$chargebackCount} failed debtors.");
        }

        return self::SUCCESS;
    }

    private function pluckLimitedIds($query, ?int $limit): array
    {
        $query = clone $query;
        $query->orderBy('id');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->pluck('id')->all();
    }

    private function updateByIds(array $ids, string $status): int
    {
        if (empty($ids)) {
            return 0;
        }

        return Debtor::whereIn('id', $ids)->update(['status' => $status]);
    }
}
