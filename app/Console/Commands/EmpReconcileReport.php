<?php

/**
 * EmpReconcileReport - Compare EMP portal CSV export with our billing_attempts database.
 * Uses temporary DB table for memory-efficient comparison of large datasets.
 * Compares by unique_id only (no date filtering) for accurate SDD reconciliation.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmpReconcileReport extends Command
{
    protected $signature = 'emp:reconcile
        {file : Path to EMP CSV export file}
        {--export : Save detailed discrepancies to CSV}';

    protected $description = 'Compare EMP portal CSV export with our billing_attempts database. Matches by unique_id without date filtering.';

    private const STORAGE_DISK = 's3';
    private const STORAGE_PATH = 'reports/emp-reconciliation';
    private const BATCH_INSERT_SIZE = 1000;

    private const EMP_STATUS_MAP = [
        'approved|sdd_sale' => 'approved',
        'chargebacked|sdd_sale' => 'chargebacked',
        'approved|chargeback' => 'chargeback_event',
        'error|sdd_sale' => 'error',
        'approved|sdd_refund' => 'refund',
        'error|sdd_refund' => 'refund_error',
        'declined|sdd_refund' => 'refund_declined',
        'pending_async|sdd_refund' => 'refund_pending',
    ];

    private string $tempTable;

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $shouldExport = $this->option('export');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info('=== EMP Reconciliation Report ===');
        $this->newLine();

        $startTime = microtime(true);

        $this->tempTable = 'tmp_emp_reconcile_' . getmypid();
        $this->createTempTable();

        try {
            $this->info('Reading EMP CSV and loading into temp table...');
            $empSummary = $this->loadCsvToTempTable($filePath);

            if ($empSummary['total'] === 0) {
                $this->error('No transactions found in CSV');
                return 1;
            }

            $this->info("Loaded {$empSummary['total']} rows in " . $this->elapsed($startTime));
            $this->info("Date range: {$empSummary['date_from']} to {$empSummary['date_to']}");
            $this->info("Terminals: " . implode(', ', $empSummary['terminals']));
            $this->newLine();

            $this->displayEmpSummary($empSummary);

            $this->info('Querying our database (by unique_id match, no date filter)...');
            $dbTime = microtime(true);
            $dbSummary = $this->getDbSummary();
            $this->info("DB query completed in " . $this->elapsed($dbTime));
            $this->displayDbSummary($dbSummary);

            $this->newLine();
            $this->info('=== COMPARISON (by unique_id only) ===');
            $compareTime = microtime(true);
            $discrepancies = $this->compareViaSql();
            $this->info("Comparison completed in " . $this->elapsed($compareTime));
            $this->displayComparison($empSummary, $dbSummary, $discrepancies);

            if ($shouldExport) {
                $this->exportReport($empSummary, $dbSummary, $discrepancies);
            }

            $this->info('Total time: ' . $this->elapsed($startTime));
        } finally {
            $this->dropTempTable();
        }

        return 0;
    }

    private function elapsed(float $start): string
    {
        return round(microtime(true) - $start, 2) . 's';
    }

    private function createTempTable(): void
    {
        DB::statement("
            CREATE TEMPORARY TABLE {$this->tempTable} (
                unique_id VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                raw_status VARCHAR(32) NOT NULL,
                type VARCHAR(32) NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                date_time TIMESTAMP NULL,
                terminal VARCHAR(255) NULL,
                iban VARCHAR(64) NULL,
                bic VARCHAR(16) NULL,
                customer_name VARCHAR(255) NULL,
                merchant_tx_id VARCHAR(255) NULL,
                PRIMARY KEY (unique_id)
            )
        ");
    }

    private function dropTempTable(): void
    {
        DB::statement("DROP TABLE IF EXISTS {$this->tempTable}");
    }

    private function loadCsvToTempTable(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['total' => 0];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['total' => 0];
        }

        $headers = array_map('trim', $headers);

        $idx = [];
        foreach (['Unique ID', 'Status', 'Type', 'Amount (with decimal mark per currency exponent)',
                   'DateTime (UTC)', 'Terminal', 'IBAN / Account No', 'BIC / SWIFT code',
                   'Customer name', 'Card Holder', 'Merchant Transaction id', 'Currency'] as $col) {
            $idx[$col] = array_search($col, $headers);
        }

        $statusCounts = [];
        $terminals = [];
        $dateFrom = null;
        $dateTo = null;
        $totalAmount = [
            'approved' => 0,
            'chargebacked' => 0,
            'chargeback_event' => 0,
            'error' => 0,
        ];
        $total = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $uniqueId = $idx['Unique ID'] !== false ? trim($row[$idx['Unique ID']] ?? '') : '';
            if (empty($uniqueId)) {
                continue;
            }

            $status = trim($row[$idx['Status']] ?? '');
            $type = trim($row[$idx['Type']] ?? '');
            $amount = (float) str_replace(',', '.', $row[$idx['Amount (with decimal mark per currency exponent)']] ?? '0');
            $dateTime = trim($row[$idx['DateTime (UTC)']] ?? '');

            $mappedStatus = self::EMP_STATUS_MAP["{$status}|{$type}"] ?? "{$status}|{$type}";
            $statusCounts[$mappedStatus] = ($statusCounts[$mappedStatus] ?? 0) + 1;

            if (isset($totalAmount[$mappedStatus])) {
                $totalAmount[$mappedStatus] += $amount;
            }

            $terminal = trim($row[$idx['Terminal']] ?? '');
            if ($terminal && !in_array($terminal, $terminals)) {
                $terminals[] = $terminal;
            }

            $date = substr($dateTime, 0, 10);
            if ($date) {
                if (!$dateFrom || $date < $dateFrom) {
                    $dateFrom = $date;
                }
                if (!$dateTo || $date > $dateTo) {
                    $dateTo = $date;
                }
            }

            $customerName = trim($row[$idx['Customer name']] ?? '');
            if (empty($customerName)) {
                $customerName = trim($row[$idx['Card Holder']] ?? '');
            }

            $batch[] = [
                'unique_id' => substr($uniqueId, 0, 64),
                'status' => $mappedStatus,
                'raw_status' => $status,
                'type' => $type,
                'amount' => $amount,
                'currency' => trim($row[$idx['Currency']] ?? 'EUR'),
                'date_time' => !empty($dateTime) ? $dateTime : null,
                'terminal' => $terminal,
                'iban' => trim($row[$idx['IBAN / Account No']] ?? ''),
                'bic' => trim($row[$idx['BIC / SWIFT code']] ?? ''),
                'customer_name' => substr($customerName, 0, 255),
                'merchant_tx_id' => trim($row[$idx['Merchant Transaction id']] ?? ''),
            ];

            $total++;

            if (count($batch) >= self::BATCH_INSERT_SIZE) {
                DB::table($this->tempTable)->insert($batch);
                $batch = [];
                $this->output->write('.');
            }
        }

        if (!empty($batch)) {
            DB::table($this->tempTable)->insert($batch);
        }

        fclose($handle);
        $this->newLine();

        return [
            'total' => $total,
            'status_counts' => $statusCounts,
            'terminals' => $terminals,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amounts' => $totalAmount,
        ];
    }

    private function getDbSummary(): array
    {
        $rows = DB::select("
            SELECT ba.status, COUNT(*) as cnt, SUM(ba.amount::numeric) as total_amount
            FROM billing_attempts ba
            INNER JOIN {$this->tempTable} t ON t.unique_id = ba.unique_id
            GROUP BY ba.status
        ");

        $statusCounts = [];
        $totalAmount = [];
        $total = 0;

        foreach ($rows as $row) {
            $statusCounts[$row->status] = (int) $row->cnt;
            $totalAmount[$row->status] = (float) $row->total_amount;
            $total += (int) $row->cnt;
        }

        return [
            'total' => $total,
            'status_counts' => $statusCounts,
            'amounts' => $totalAmount,
        ];
    }

    private function compareViaSql(): array
    {
        $inEmpNotDb = DB::select("
            SELECT t.unique_id, t.status, t.amount, t.customer_name, t.iban, t.date_time
            FROM {$this->tempTable} t
            LEFT JOIN billing_attempts ba ON ba.unique_id = t.unique_id
            WHERE t.status IN ('approved', 'chargebacked', 'error')
            AND ba.id IS NULL
            ORDER BY t.date_time
        ");

        $statusMismatch = DB::select("
            SELECT t.unique_id,
                   t.status as emp_status,
                   ba.status as db_status,
                   t.amount as emp_amount,
                   ba.amount as db_amount,
                   t.date_time as emp_date,
                   ba.created_at as db_date
            FROM {$this->tempTable} t
            INNER JOIN billing_attempts ba ON ba.unique_id = t.unique_id
            WHERE t.status IN ('approved', 'chargebacked', 'error')
            AND t.status != ba.status
            ORDER BY t.date_time
        ");

        $amountMismatch = DB::select("
            SELECT t.unique_id,
                   t.amount as emp_amount,
                   ba.amount as db_amount,
                   ABS(t.amount - ba.amount::numeric) as diff,
                   ba.status
            FROM {$this->tempTable} t
            INNER JOIN billing_attempts ba ON ba.unique_id = t.unique_id
            WHERE t.status IN ('approved', 'chargebacked', 'error')
            AND ABS(t.amount - ba.amount::numeric) > 0.01
            ORDER BY ABS(t.amount - ba.amount::numeric) DESC
        ");

        return [
            'in_emp_not_db' => $inEmpNotDb,
            'status_mismatch' => $statusMismatch,
            'amount_mismatch' => $amountMismatch,
        ];
    }

    private function displayEmpSummary(array $empSummary): void
    {
        $this->info('--- EMP Portal Data ---');
        $rows = [];
        foreach ($empSummary['status_counts'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Status', 'Count'], $rows);

        $this->info('EMP Volumes:');
        foreach ($empSummary['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $this->line("  {$status}: EUR " . number_format($amount, 2));
            }
        }
        $this->newLine();
    }

    private function displayDbSummary(array $dbSummary): void
    {
        $this->info('--- Our Database (matched records only) ---');
        $rows = [];
        foreach ($dbSummary['status_counts'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Status', 'Count'], $rows);

        $this->info('DB Volumes:');
        foreach ($dbSummary['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $this->line("  {$status}: EUR " . number_format($amount, 2));
            }
        }
        $this->newLine();
    }

    private function displayComparison(array $empSummary, array $dbSummary, array $discrepancies): void
    {
        $empSalesCount = ($empSummary['status_counts']['approved'] ?? 0)
            + ($empSummary['status_counts']['chargebacked'] ?? 0)
            + ($empSummary['status_counts']['error'] ?? 0);

        $this->table(['Metric', 'EMP', 'Our DB', 'Diff'], [
            ['Approved',
                $empSummary['status_counts']['approved'] ?? 0,
                $dbSummary['status_counts']['approved'] ?? 0,
                ($empSummary['status_counts']['approved'] ?? 0) - ($dbSummary['status_counts']['approved'] ?? 0)],
            ['Chargebacked',
                $empSummary['status_counts']['chargebacked'] ?? 0,
                $dbSummary['status_counts']['chargebacked'] ?? 0,
                ($empSummary['status_counts']['chargebacked'] ?? 0) - ($dbSummary['status_counts']['chargebacked'] ?? 0)],
            ['Errors',
                $empSummary['status_counts']['error'] ?? 0,
                $dbSummary['status_counts']['error'] ?? 0,
                ($empSummary['status_counts']['error'] ?? 0) - ($dbSummary['status_counts']['error'] ?? 0)],
            ['CB Events (EMP only)',
                $empSummary['status_counts']['chargeback_event'] ?? 0, '-', '-'],
        ]);

        $this->newLine();

        $inEmpNotDb = count($discrepancies['in_emp_not_db']);
        $statusMismatch = count($discrepancies['status_mismatch']);
        $amountMismatch = count($discrepancies['amount_mismatch']);

        $this->info('--- Discrepancies ---');
        $this->table(['Type', 'Count'], [
            ['In EMP but not in our DB', $inEmpNotDb],
            ['Status mismatch', $statusMismatch],
            ['Amount mismatch (>0.01)', $amountMismatch],
        ]);

        if ($statusMismatch > 0) {
            $this->warn("Status mismatches (first 20):");
            $sample = array_slice($discrepancies['status_mismatch'], 0, 20);
            $this->table(
                ['Unique ID', 'EMP Status', 'DB Status', 'EMP Amount', 'DB Amount'],
                array_map(fn($d) => [
                    substr($d->unique_id, 0, 16) . '...',
                    $d->emp_status,
                    $d->db_status,
                    'EUR ' . number_format((float) $d->emp_amount, 2),
                    'EUR ' . number_format((float) $d->db_amount, 2),
                ], $sample)
            );
        }

        if ($amountMismatch > 0) {
            $this->warn("Amount mismatches (first 10):");
            $sample = array_slice($discrepancies['amount_mismatch'], 0, 10);
            $this->table(
                ['Unique ID', 'EMP Amount', 'DB Amount', 'Diff'],
                array_map(fn($d) => [
                    substr($d->unique_id, 0, 16) . '...',
                    'EUR ' . number_format((float) $d->emp_amount, 2),
                    'EUR ' . number_format((float) $d->db_amount, 2),
                    'EUR ' . number_format((float) $d->diff, 2),
                ], $sample)
            );
        }

        if ($inEmpNotDb > 0) {
            $this->warn("Missing in our DB (first 10):");
            $sample = array_slice($discrepancies['in_emp_not_db'], 0, 10);
            $this->table(
                ['Unique ID', 'Status', 'Amount', 'IBAN', 'Name'],
                array_map(fn($d) => [
                    substr($d->unique_id, 0, 16) . '...',
                    $d->status,
                    'EUR ' . number_format((float) $d->amount, 2),
                    $d->iban ?? '',
                    $d->customer_name ?? '',
                ], $sample)
            );
        }

        $this->newLine();
        $totalIssues = $inEmpNotDb + $statusMismatch + $amountMismatch;
        if ($totalIssues === 0) {
            $this->info('RECONCILIATION PASSED - No discrepancies found');
        } else {
            $this->warn("RECONCILIATION ISSUES - {$totalIssues} total discrepancies found");
        }
        $this->newLine();
    }

    private function exportReport(array $empSummary, array $dbSummary, array $discrepancies): void
    {
        $dateFrom = $empSummary['date_from'];
        $dateTo = $empSummary['date_to'];
        $timestamp = date('Ymd_His');
        $basePath = self::STORAGE_PATH . "/{$dateFrom}_{$dateTo}";

        $summary = $this->buildSummaryReport($empSummary, $dbSummary, $discrepancies);
        $summaryPath = "{$basePath}/summary_{$timestamp}.txt";
        Storage::disk(self::STORAGE_DISK)->put($summaryPath, $summary);
        $this->info("Summary saved: {$summaryPath}");

        if ($this->hasDiscrepancies($discrepancies)) {
            $csv = $this->buildDiscrepancyCsv($discrepancies);
            $csvPath = "{$basePath}/discrepancies_{$timestamp}.csv";
            Storage::disk(self::STORAGE_DISK)->put($csvPath, $csv);
            $this->info("Discrepancies saved: {$csvPath}");
        }

        $originalPath = "{$basePath}/emp_export_original.csv";
        if (!Storage::disk(self::STORAGE_DISK)->exists($originalPath)) {
            $stream = fopen($this->argument('file'), 'r');
            Storage::disk(self::STORAGE_DISK)->writeStream($originalPath, $stream);
            fclose($stream);
            $this->info("Original EMP CSV archived: {$originalPath}");
        } else {
            $this->info("Original EMP CSV already archived, skipping");
        }

        $this->newLine();
        $this->info("All files saved to S3: {$basePath}/");
    }

    private function buildSummaryReport(array $empSummary, array $dbSummary, array $discrepancies): string
    {
        $lines = [];
        $lines[] = '=== EMP RECONCILIATION REPORT ===';
        $lines[] = 'Generated: ' . now()->toDateTimeString();
        $lines[] = "Period: {$empSummary['date_from']} to {$empSummary['date_to']}";
        $lines[] = 'Terminals: ' . implode(', ', $empSummary['terminals']);
        $lines[] = 'Method: unique_id matching (no date filtering)';
        $lines[] = '';
        $lines[] = '--- EMP PORTAL ---';
        foreach ($empSummary['status_counts'] as $status => $count) {
            $lines[] = "  {$status}: {$count}";
        }
        $lines[] = '';
        $lines[] = 'EMP Volumes:';
        foreach ($empSummary['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $lines[] = "  {$status}: EUR " . number_format($amount, 2);
            }
        }
        $lines[] = '';
        $lines[] = '--- OUR DATABASE (matched records) ---';
        foreach ($dbSummary['status_counts'] as $status => $count) {
            $lines[] = "  {$status}: {$count}";
        }
        $lines[] = '';
        $lines[] = 'DB Volumes:';
        foreach ($dbSummary['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $lines[] = "  {$status}: EUR " . number_format($amount, 2);
            }
        }
        $lines[] = '';
        $lines[] = '--- DISCREPANCIES ---';
        $lines[] = 'In EMP not in DB: ' . count($discrepancies['in_emp_not_db']);
        $lines[] = 'Status mismatch: ' . count($discrepancies['status_mismatch']);
        $lines[] = 'Amount mismatch: ' . count($discrepancies['amount_mismatch']);

        $total = count($discrepancies['in_emp_not_db'])
            + count($discrepancies['status_mismatch'])
            + count($discrepancies['amount_mismatch']);

        $lines[] = '';
        if ($total === 0) {
            $lines[] = 'RESULT: RECONCILIATION PASSED';
        } else {
            $lines[] = "RESULT: {$total} DISCREPANCIES FOUND";
        }

        return implode("\n", $lines);
    }

    private function buildDiscrepancyCsv(array $discrepancies): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Type', 'Unique ID', 'EMP Status', 'DB Status',
            'EMP Amount', 'DB Amount', 'Details',
        ]);

        foreach ($discrepancies['in_emp_not_db'] as $row) {
            fputcsv($handle, [
                'IN_EMP_NOT_DB', $row->unique_id, $row->status, '-',
                $row->amount, '-', ($row->customer_name ?? '') . ' | ' . ($row->iban ?? ''),
            ]);
        }

        foreach ($discrepancies['status_mismatch'] as $row) {
            fputcsv($handle, [
                'STATUS_MISMATCH', $row->unique_id, $row->emp_status, $row->db_status,
                $row->emp_amount, $row->db_amount, '',
            ]);
        }

        foreach ($discrepancies['amount_mismatch'] as $row) {
            fputcsv($handle, [
                'AMOUNT_MISMATCH', $row->unique_id, $row->status, $row->status,
                $row->emp_amount, $row->db_amount, 'Diff: ' . number_format((float) $row->diff, 2),
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function hasDiscrepancies(array $discrepancies): bool
    {
        return !empty($discrepancies['in_emp_not_db'])
            || !empty($discrepancies['status_mismatch'])
            || !empty($discrepancies['amount_mismatch']);
    }
}
