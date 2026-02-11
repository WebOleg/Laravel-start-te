<?php

namespace App\Console\Commands;

use App\Models\BicBlacklist;
use App\Models\BillingAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BicBlacklistAutoCommand extends Command
{
    protected $signature = 'bic-blacklist:auto
                            {--period=30 : Days to look back}
                            {--dry-run : Show what would be blacklisted without making changes}';

    protected $description = 'Auto-blacklist BICs based on chargeback rate criteria';

    public function handle(): int
    {
        $days = (int) $this->option('period');
        $dryRun = $this->option('dry-run');
        $since = now()->subDays($days);

        $excludedCbCodes = config('tether.chargeback.excluded_cb_reason_codes', []);

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
        }

        $this->info("Analyzing BICs for last {$days} days...");

        $cbCase = $this->buildCbCountCase($excludedCbCodes);

        $results = DB::table('billing_attempts')
            ->whereRaw('COALESCE(emp_created_at, created_at) >= ?', [$since])
            ->whereNotNull('bic')
            ->where('bic', '!=', '')
            ->groupBy('bic')
            ->select([
                'bic',
                DB::raw("SUM(CASE WHEN status = '" . BillingAttempt::STATUS_APPROVED . "' THEN 1 ELSE 0 END) as approved"),
                DB::raw("{$cbCase} as chargebacked"),
            ])
            ->get();

        $added = 0;
        $skipped = 0;

        foreach ($results as $row) {
            $approved = (int) $row->approved;
            $cb = (int) $row->chargebacked;
            $total = $approved + $cb;

            if ($total === 0) {
                continue;
            }

            $cbRate = round(($cb / $total) * 100, 2);
            $criteria = $this->matchesCriteria($total, $cbRate);

            if ($criteria === null) {
                continue;
            }

            if (BicBlacklist::isBlacklisted($row->bic)) {
                $skipped++;
                continue;
            }

            $this->line("  {$row->bic}: {$total} tx, {$cbRate}% CB -> {$criteria}");

            if (! $dryRun) {
                BicBlacklist::create([
                    'bic' => $row->bic,
                    'is_prefix' => false,
                    'reason' => "Auto: {$total} tx, {$cbRate}% CB rate",
                    'source' => BicBlacklist::SOURCE_AUTO,
                    'auto_criteria' => $criteria,
                    'blacklisted_by' => 'system',
                    'stats_snapshot' => [
                        'approved' => $approved,
                        'chargebacked' => $cb,
                        'total' => $total,
                        'cb_rate' => $cbRate,
                        'period_days' => $days,
                        'calculated_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            $added++;
        }

        $this->newLine();
        $this->info("Done: {$added} blacklisted, {$skipped} already blacklisted");

        return 0;
    }

    private function matchesCriteria(int $total, float $cbRate): ?string
    {
        if ($total > 50 && $cbRate > 50) {
            return 'Rule 1: >50 tx AND >50% CB';
        }

        if ($total >= 10 && $cbRate > 80) {
            return 'Rule 2: >=10 tx AND >80% CB';
        }

        return null;
    }

    private function buildCbCountCase(array $excludedCodes): string
    {
        if (empty($excludedCodes)) {
            return "SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "' THEN 1 ELSE 0 END)";
        }

        $quoted = array_map(fn($c) => "'" . addslashes($c) . "'", $excludedCodes);
        $list = implode(', ', $quoted);

        return "SUM(CASE WHEN status = '" . BillingAttempt::STATUS_CHARGEBACKED . "'
                AND (chargeback_reason_code IS NULL OR chargeback_reason_code = '' OR chargeback_reason_code NOT IN ({$list}))
                THEN 1 ELSE 0 END)";
    }
}
