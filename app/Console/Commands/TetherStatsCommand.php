<?php

/**
 * Artisan command for financial statistics by EMP account.
 * Reports gross revenue, chargebacks, net revenue, and chargeback rate per account.
 */

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Models\EmpAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TetherStatsCommand extends Command
{
    protected $signature = 'tether:stats
                            {--month= : Month number (1-12), default: current month}
                            {--year=  : Year (YYYY), default: current year}
                            {--account= : Filter by EMP account slug}
                            {--cb-threshold=2 : Chargeback rate threshold for warning (percent)}';

    protected $description = 'Show financial statistics by EMP account for a given month';

    private const CB_CRITICAL = 5.0;

    public function handle(): int
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year  = (int) ($this->option('year')  ?: now()->year);
        $accountSlug  = $this->option('account');
        $cbThreshold  = (float) ($this->option('cb-threshold') ?? 2.0);

        if ($month < 1 || $month > 12) {
            $this->error('Invalid month. Must be between 1 and 12.');
            return Command::FAILURE;
        }

        $periodLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');
        $this->info("Financial statistics for: {$periodLabel}");
        $this->newLine();

        $accountQuery = EmpAccount::ordered();
        if ($accountSlug) {
            $accountQuery->where('slug', $accountSlug);
        }
        $accounts = $accountQuery->get();

        if ($accounts->isEmpty()) {
            $this->error('No EMP accounts found.');
            return Command::FAILURE;
        }

        $approved = BillingAttempt::where('status', BillingAttempt::STATUS_APPROVED)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->selectRaw('emp_account_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('emp_account_id')
            ->get()
            ->keyBy('emp_account_id');

        $chargebacked = BillingAttempt::where('status', BillingAttempt::STATUS_CHARGEBACKED)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->selectRaw('emp_account_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('emp_account_id')
            ->get()
            ->keyBy('emp_account_id');

        $rows        = [];
        $totalGross  = 0;
        $totalCb     = 0;
        $totalTxns   = 0;
        $totalCbTxns = 0;
        $warnings    = [];

        foreach ($accounts as $account) {
            $app = $approved[$account->id]  ?? null;
            $cb  = $chargebacked[$account->id] ?? null;

            if (is_null($app)) {
                continue;
            }

            $gross    = (float) $app->total;
            $cbAmount = (float) ($cb->total ?? 0);
            $net      = $gross - $cbAmount;
            $txns     = (int) $app->count;
            $cbTxns   = (int) ($cb->count ?? 0);
            $cbRate   = $cbTxns > 0
                ? round($cbTxns / ($txns + $cbTxns) * 100, 2)
                : 0.0;

            $totalGross  += $gross;
            $totalCb     += $cbAmount;
            $totalTxns   += $txns;
            $totalCbTxns += $cbTxns;

            $rateLabel = $cbRate . '%';
            if ($cbRate >= self::CB_CRITICAL) {
                $rateLabel = '<fg=red>' . $rateLabel . '</>';
                $warnings[] = "CRITICAL: {$account->name} cb rate is {$cbRate}% — exceeds " . self::CB_CRITICAL . "% threshold";
            } elseif ($cbRate >= $cbThreshold) {
                $rateLabel = '<fg=yellow>' . $rateLabel . '</>';
                $warnings[] = "WARNING: {$account->name} cb rate is {$cbRate}% — exceeds {$cbThreshold}% threshold";
            }

            $rows[] = [
                $account->name,
                number_format($gross, 2) . ' EUR',
                $cbTxns > 0 ? '-' . number_format($cbAmount, 2) . ' EUR' : '—',
                number_format($net, 2) . ' EUR',
                $txns,
                $cbTxns ?: '—',
                $rateLabel,
            ];
        }

        if (empty($rows)) {
            $this->warn('No transactions found for this period.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Account', 'Gross', 'Chargebacks', 'Net', 'Txns', 'CB Txns', 'CB Rate'],
            $rows
        );

        $totalNet    = $totalGross - $totalCb;
        $totalCbRate = $totalCbTxns > 0
            ? round($totalCbTxns / ($totalTxns + $totalCbTxns) * 100, 2)
            : 0.0;

        $this->newLine();
        $this->line('<fg=white;options=bold>TOTAL</>');
        $this->table(
            ['Gross', 'Chargebacks', 'Net', 'Txns', 'CB Txns', 'CB Rate'],
            [[
                number_format($totalGross, 2) . ' EUR',
                $totalCb > 0 ? '-' . number_format($totalCb, 2) . ' EUR' : '—',
                number_format($totalNet, 2) . ' EUR',
                $totalTxns,
                $totalCbTxns ?: '—',
                $totalCbRate . '%',
            ]]
        );

        if (!empty($warnings)) {
            $this->newLine();
            foreach ($warnings as $warning) {
                if (str_starts_with($warning, 'CRITICAL')) {
                    $this->error($warning);
                } else {
                    $this->warn($warning);
                }
            }
        }

        return Command::SUCCESS;
    }
}
