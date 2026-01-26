<?php

/**
 * Artisan command to sync chargebacks from EMP API.
 * Backup mechanism for missed webhooks.
 */

namespace App\Console\Commands;

use App\Services\Emp\EmpChargebackSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class EmpSyncChargebacks extends Command
{
    protected $signature = 'emp:sync-chargebacks
                            {--date= : Specific date to sync (YYYY-MM-DD), default: yesterday}
                            {--start-date= : Start date for range sync (YYYY-MM-DD)}
                            {--end-date= : End date for range sync (YYYY-MM-DD)}
                            {--days=1 : Number of days to look back from today}
                            {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Sync chargebacks from EMP /chargebacks/by_date API';

    public function handle(EmpChargebackSyncService $service): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        if ($this->option('start-date') && $this->option('end-date')) {
            return $this->syncDateRange($service, $dryRun);
        }

        if ($this->option('date')) {
            return $this->syncSingleDate($service, $this->option('date'), $dryRun);
        }

        $days = (int) $this->option('days');
        return $this->syncLastDays($service, $days, $dryRun);
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
        $endDate = $this->option('end-date');

        $this->info("Syncing chargebacks from {$startDate} to {$endDate}");

        $results = $service->syncByDateRange($startDate, $endDate, $dryRun);

        $totalStats = [
            'total_fetched' => 0,
            'matched' => 0,
            'already_processed' => 0,
            'unmatched' => 0,
            'errors' => 0,
            'blacklisted' => 0,
        ];

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
        $endDate = Carbon::yesterday();
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

        $totalStats = [
            'total_fetched' => 0,
            'matched' => 0,
            'already_processed' => 0,
            'unmatched' => 0,
            'errors' => 0,
            'blacklisted' => 0,
        ];

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
                ['Fetched from API', $stats['total_fetched'] ?? 0],
                ['Matched & Updated', $stats['matched'] ?? 0],
                ['Already Processed', $stats['already_processed'] ?? 0],
                ['Unmatched', $stats['unmatched'] ?? 0],
                ['Blacklisted', $stats['blacklisted'] ?? 0],
                ['Errors', $stats['errors'] ?? 0],
            ]
        );

        if (($stats['unmatched'] ?? 0) > 10) {
            $this->warn("⚠ High unmatched count ({$stats['unmatched']}) - review logs");
        }
    }
}
