<?php

/**
 * Artisan command to sync chargebacks from EMP API.
 * Backup mechanism for missed webhooks.
 * After each sync run, displays chargeback rate summary per EMP account.
 */

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Models\EmpAccount;
use App\Services\Emp\EmpChargebackSyncService;
use App\Traits\WithLogContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EmpSyncChargebacks extends Command
{
    use WithLogContext;

    protected $signature = 'emp:sync-chargebacks
                            {--date= : Specific date to sync (YYYY-MM-DD), default: yesterday}
                            {--start-date= : Start date for range sync (YYYY-MM-DD)}
                            {--end-date= : End date for range sync (YYYY-MM-DD)}
                            {--days=1 : Number of days to look back from today}
                            {--dry-run : Show what would be processed without making changes}
                            {--cb-threshold=2 : Chargeback rate threshold for warning (percent)}
                            {--cb-window=30 : Number of days to calculate chargeback rate over}';

    protected $description = 'Sync chargebacks from EMP /chargebacks/by_date API';

    private const CB_CRITICAL = 5.0;

    public function handle(EmpChargebackSyncService $service): int
    {
        $this->initLogContext();

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($this->option('start-date') && $this->option('end-date')) {
            $result = $this->syncDateRange($service, $dryRun);
        } elseif ($this->option('date')) {
            $result = $this->syncSingleDate($service, $this->option('date'), $dryRun);
        } else {
            $days = (int) $this->option('days');
            $result = $this->syncLastDays($service, $days, $dryRun);
        }

        if (!$dryRun) {
            $this->displayCbRateSummary();
        }

        return $result;
    }

    private function syncSingleDate(EmpChargebackSyncService $service, string $date, bool $dryRun): int
    {
        $this->info("Syncing chargebacks for: {$date}");

        $stats = $service->syncByDate($date, $dryRun);

        $this->displayStats($stats);

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncDateRange(EmpChargebackSyncService $service, bool $dryRun): int
    {
        $startDate = $this->option('start-date');
        $endDate   = $this->option('end-date');

        $this->info("Syncing chargebacks from {$startDate} to {$endDate}");

        $results = $service->syncByDateRange($startDate, $endDate, $dryRun);

        $totalStats = $this->emptyTotalStats();

        foreach ($results as $date => $stats) {
            $this->line("\n--- {$date} ---");
            $this->displayStats($stats);
            foreach ($totalStats as $key => &$value) {
                $value += $stats[$key] ?? 0;
            }
        }

        $this->newLine();
        $this->info('=== TOTAL ===');
        $this->displayStats($totalStats);

        return $totalStats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function syncLastDays(EmpChargebackSyncService $service, int $days, bool $dryRun): int
    {
        $endDate   = Carbon::yesterday();
        $startDate = Carbon::yesterday()->subDays($days - 1);

        $this->info("Syncing chargebacks for last {$days} day(s): {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");

        if ($days === 1) {
            return $this->syncSingleDate($service, $endDate->format('Y-m-d'), $dryRun);
        }

        $results = $service->syncByDateRange(
            $startDate->format('Y-m-d'),
            $endDate->format('Y-m-d'),
            $dryRun
        );

        $totalStats = $this->emptyTotalStats();

        foreach ($results as $date => $stats) {
            $this->line("\n--- {$date} ---");
            $this->displayStats($stats);
            foreach ($totalStats as $key => &$value) {
                $value += $stats[$key] ?? 0;
            }
        }

        $this->newLine();
        $this->info('=== TOTAL ===');
        $this->displayStats($totalStats);

        return $totalStats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function displayStats(array $stats): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Fetched from API',  $stats['total_fetched']     ?? 0],
                ['Matched & Updated', $stats['matched']           ?? 0],
                ['Already Processed', $stats['already_processed'] ?? 0],
                ['Unmatched',         $stats['unmatched']         ?? 0],
                ['Blacklisted',       $stats['blacklisted']       ?? 0],
                ['Errors',            $stats['errors']            ?? 0],
            ]
        );

        if (($stats['unmatched'] ?? 0) > 10) {
            $this->warn("High unmatched count ({$stats['unmatched']}) - review logs");
        }
    }

    private function displayCbRateSummary(): void
    {
        $window      = (int) ($this->option('cb-window') ?? 30);
        $cbThreshold = (float) ($this->option('cb-threshold') ?? 2.0);
        $since       = now()->subDays($window)->startOfDay();

        $this->newLine();
        $this->info("=== Chargeback Rate Summary (last {$window} days) ===");

        $approved = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)
            ->where('created_at', '>=', $since)
            ->selectRaw('emp_account_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('emp_account_id')
            ->get()
            ->keyBy('emp_account_id');

        $chargebacked = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->where('created_at', '>=', $since)
            ->selectRaw('emp_account_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('emp_account_id')
            ->get()
            ->keyBy('emp_account_id');

        $rows     = [];
        $hasAlert = false;

        foreach (EmpAccount::ordered()->get() as $account) {
            $app = $approved[$account->id]    ?? null;
            $cb  = $chargebacked[$account->id] ?? null;

            if (is_null($app)) {
                continue;
            }

            $appCount = (int) $app->count;
            $cbCount  = (int) ($cb->count ?? 0);
            $cbRate   = $cbCount > 0
                ? round($cbCount / ($appCount + $cbCount) * 100, 2)
                : 0.0;

            $rateLabel = $cbRate . '%';

            if ($cbRate >= self::CB_CRITICAL) {
                $rateLabel = '<fg=red>' . $rateLabel . '</>';
                $hasAlert  = true;
                Log::critical('Chargeback rate CRITICAL after sync', [
                    'account'  => $account->name,
                    'cb_rate'  => $cbRate,
                    'window_days' => $window,
                ]);
            } elseif ($cbRate >= $cbThreshold) {
                $rateLabel = '<fg=yellow>' . $rateLabel . '</>';
                $hasAlert  = true;
                Log::warning('Chargeback rate elevated after sync', [
                    'account'  => $account->name,
                    'cb_rate'  => $cbRate,
                    'window_days' => $window,
                ]);
            }

            $rows[] = [
                $account->name,
                $appCount,
                $cbCount ?: '—',
                number_format((float) ($cb->total ?? 0), 2) . ' EUR',
                $rateLabel,
            ];
        }

        if (empty($rows)) {
            $this->line('No data for this period.');
            return;
        }

        $this->table(
            ['Account', 'Approved', 'CB Txns', 'CB Amount', 'CB Rate'],
            $rows
        );

        if ($hasAlert) {
            $this->newLine();
            $this->error("One or more accounts exceed the {$cbThreshold}% chargeback threshold. Review logs mediately.");
        }
    }

    private function emptyTotalStats(): array
    {
        return [
            'total_fetched'    => 0,
            'matched'          => 0,
            'already_processed' => 0,
            'unmatched'        => 0,
            'errors'           => 0,
            'blacklisted'      => 0,
        ];
    }
}
