<?php

namespace App\Console\Commands;

use App\Jobs\EmpRefreshByDateJob;
use App\Models\EmpAccount;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class EmpRefreshCommand extends Command
{
    protected $signature = 'emp:refresh
                            {--from= : Start date (YYYY-MM-DD), default: yesterday}
                            {--to= : End date (YYYY-MM-DD), default: yesterday}
                            {--account-id= : Specific EMP account ID to refresh}
                            {--watch : Monitor job progress in real-time}';

    protected $description = 'Refresh EMP data for specified date range';

    public function handle(): int
    {
        // Determine date range
        try {
            $from = $this->option('from') 
                ? Carbon::parse($this->option('from'))
                : now()->subDay();
            
            $to = $this->option('to')
                ? Carbon::parse($this->option('to'))
                : ($this->option('from') ? Carbon::parse($this->option('from')) : now()->subDay());
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use YYYY-MM-DD.');
            return 1;
        }

        if ($from->gt($to)) {
            $this->error('Start date (--from) cannot be after end date (--to)');
            return 1;
        }

        // Validate date range
        if ($from->diffInDays($to) > 90) {
            $this->error('Date range cannot exceed 90 days');
            return 1;
        }

        // Check for existing active job
        $existingJob = Cache::get('emp_refresh_active');
        if ($existingJob && $existingJob['status'] === 'processing') {
            $jobStatus = Cache::get("emp_refresh_{$existingJob['job_id']}");
            if ($jobStatus) {
                $this->warn('Refresh already in progress: ' . $existingJob['job_id']);
                if (!$this->option('watch')) {
                    $this->info('Use --watch flag to monitor progress');
                }
                return 1;
            }
            Cache::forget('emp_refresh_active');
        }

        // Determine which accounts to refresh
        $accountIds = [];
        if ($this->option('account-id')) {
            $accountId = $this->option('account-id');
            if (!EmpAccount::where('id', $accountId)->exists()) {
                $this->error("EMP account not found: {$accountId}");
                return 1;
            }
            $accountIds = [$accountId];
        } else {
            $accountIds = EmpAccount::pluck('id')->toArray();
            
            if (empty($accountIds)) {
                $this->error('No EMP accounts configured');
                return 1;
            }
        }

        $jobId = Str::uuid()->toString();

        // Set up cache tracking
        Cache::put('emp_refresh_active', [
            'job_id' => $jobId,
            'status' => 'processing',
            'started_at' => now()->toIso8601String(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'account_ids' => $accountIds,
        ], 7200);

        Cache::put("emp_refresh_{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'stats' => [
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'errors' => 0,
            ],
            'accounts_total' => count($accountIds),
            'accounts_processed' => 0,
            'current_account' => null,
            'started_at' => now()->toIso8601String(),
        ], 7200);

        // Dispatch the refresh job
        EmpRefreshByDateJob::dispatch(
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $jobId,
            $accountIds
        );

        $this->displayHeader();
        $this->displayJobInfo($jobId, $from, $to, $accountIds);

        // Watch mode: monitor progress
        if ($this->option('watch')) {
            return $this->watchJobProgress($jobId, $accountIds);
        }

        return 0;
    }

    private function watchJobProgress(string $jobId, array $accountIds): int
    {
        $this->newLine();
        $this->info('Watching job progress... (Press Ctrl+C to stop)');
        $this->newLine();

        $maxAttempts = 7200 / 5; // 2 hours with 5-second intervals
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $status = Cache::get("emp_refresh_{$jobId}");

            if (!$status) {
                $this->error('Job not found or expired');
                return 1;
            }

            $this->displayProgress($status, $jobId);

            // Check if job is completed
            if ($status['status'] === 'completed' || $status['status'] === 'failed') {
                $this->displayCompletionStats($status);
                return $status['status'] === 'completed' ? 0 : 1;
            }

            sleep(5);
            $attempt++;
        }

        $this->warn('Job monitoring timeout (2 hours reached)');
        return 1;
    }

    private function displayHeader(): void
    {
        $this->line('');
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║          EMP Refresh Job Started                           ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
    }

    private function displayJobInfo(string $jobId, Carbon $from, Carbon $to, array $accountIds): void
    {
        $this->line('');
        $this->line("<info>Job Details:</info>");
        $this->line("  <fg=gray>Job ID:</fg=gray>           <comment>{$jobId}</comment>");
        $this->line("  <fg=gray>Date Range:</fg=gray>       <comment>{$from->format('Y-m-d')}</comment> to <comment>{$to->format('Y-m-d')}</comment>");
        $this->line("  <fg=gray>Accounts:</fg=gray>         <comment>" . count($accountIds) . "</comment>");
        $this->line("  <fg=gray>Started At:</fg=gray>       <comment>" . now()->format('H:i:s') . "</comment>");
    }

    private function displayProgress(array $status, string $jobId): void
    {
        $progress = $status['progress'] ?? 0;
        $stats = $status['stats'] ?? [];
        $accountsProcessed = $status['accounts_processed'] ?? 0;
        $accountsTotal = $status['accounts_total'] ?? 0;
        $currentAccount = $status['current_account'] ?? 'N/A';

        $this->clearPreviousOutput();

        $this->line("\n<info>Current Status:</info>");
        $this->line("  <fg=gray>Job ID:</fg=gray>              <comment>{$jobId}</comment>");
        $this->line("  <fg=gray>Status:</fg=gray>             <comment>" . strtoupper($status['status']) . "</comment>");
        $this->line("  <fg=gray>Progress:</fg=gray>           <comment>{$progress}%</comment>");
        $this->line("  <fg=gray>Accounts:</fg=gray>           <comment>{$accountsProcessed}/{$accountsTotal}</comment>");
        $this->line("  <fg=gray>Current Account:</fg=gray>    <comment>{$currentAccount}</comment>");

        $this->line("\n<info>Statistics:</info>");
        $this->line("  <fg=gray>Inserted:</fg=gray>          <fg=green>" . ($stats['inserted'] ?? 0) . "</fg=green>");
        $this->line("  <fg=gray>Updated:</fg=gray>           <fg=blue>" . ($stats['updated'] ?? 0) . "</fg=blue>");
        $this->line("  <fg=gray>Unchanged:</fg=gray>         <fg=cyan>" . ($stats['unchanged'] ?? 0) . "</fg=cyan>");
        $this->line("  <fg=gray>Errors:</fg=gray>            <fg=red>" . ($stats['errors'] ?? 0) . "</fg=red>");

        $total = ($stats['inserted'] ?? 0) + ($stats['updated'] ?? 0) + ($stats['unchanged'] ?? 0) + ($stats['errors'] ?? 0);
        $this->line("  <fg=gray>Total Processed:</fg=gray>   <comment>{$total}</comment>");

        $this->displayProgressBar($progress);
    }

    private function displayProgressBar(int $progress): void
    {
        $barLength = 40;
        $filledLength = (int) ($progress / 100 * $barLength);
        $emptyLength = $barLength - $filledLength;

        $bar = str_repeat('=', $filledLength) . str_repeat('-', $emptyLength);
        $this->line("\n<comment>[{$bar}] {$progress}%</comment>\n");
    }

    private function displayCompletionStats(array $status): void
    {
        $stats = $status['stats'] ?? [];
        $startedAt = Carbon::parse($status['started_at']);
        $completedAt = Carbon::parse($status['completed_at'] ?? now());
        $duration = $startedAt->diffInSeconds($completedAt);

        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║          Job Completed                                     ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');

        $this->newLine();
        $this->line('<info>Final Statistics:</info>');
        $this->line("  <fg=green>Inserted:</fg=green>          " . ($stats['inserted'] ?? 0));
        $this->line("  <fg=blue>Updated:</fg=blue>           " . ($stats['updated'] ?? 0));
        $this->line("  <fg=cyan>Unchanged:</fg=cyan>         " . ($stats['unchanged'] ?? 0));
        $this->line("  <fg=red>Errors:</fg=red>            " . ($stats['errors'] ?? 0));

        $total = ($stats['inserted'] ?? 0) + ($stats['updated'] ?? 0) + ($stats['unchanged'] ?? 0) + ($stats['errors'] ?? 0);
        $this->line("  <fg=white>Total:</fg=white>            <comment>{$total}</comment>");

        $this->newLine();
        $this->line('<info>Timing:</info>');
        $this->line("  <fg=gray>Duration:</fg=gray>          <comment>{$duration}s</comment>");
        $this->line("  <fg=gray>Started:</fg=gray>           <comment>{$startedAt->format('Y-m-d H:i:s')}</comment>");
        $this->line("  <fg=gray>Completed:</fg=gray>         <comment>{$completedAt->format('Y-m-d H:i:s')}</comment>");

        $this->newLine();
    }

    private function clearPreviousOutput(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }
}
