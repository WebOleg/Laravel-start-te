<?php

/**
 * EmpReconcileReport - Compare EMP portal CSV export with our billing_attempts database.
 * Identifies discrepancies in transaction counts, statuses, and amounts.
 * Optimized for large datasets using chunked DB queries and memory-efficient processing.
 */

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmpReconcileReport extends Command
{
    protected $signature = 'emp:reconcile
        {file : Path to EMP CSV export file}
        {--emp_account_id= : Filter our DB by specific EMP account ID}
        {--export : Save detailed discrepancies to CSV}';

    protected $description = 'Compare EMP portal CSV export with our billing_attempts database and generate reconciliation report';

    private const STORAGE_DISK = 's3';
    private const STORAGE_PATH = 'reports/emp-reconciliation';
    private const DB_CHUNK_SIZE = 5000;

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

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $empAccountId = $this->option('emp_account_id');
        $shouldExport = $this->option('export');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info('=== EMP Reconciliation Report ===');
        $this->newLine();

        $startTime = microtime(true);

        $this->info('Reading EMP CSV export...');
        $empData = $this->parseEmpCsv($filePath);

        if (empty($empData['transactions'])) {
            $this->error('No transactions found in CSV');
            return 1;
        }

        $this->info("Parsed {$empData['total']} rows in " . $this->elapsed($startTime));
        $this->info("Date range: {$empData['date_from']} to {$empData['date_to']}");
        $this->info("Terminals: " . implode(', ', $empData['terminals']));
        $this->newLine();

        $this->displayEmpSummary($empData);

        $this->info('Fetching our database records (chunked)...');
        $dbTime = microtime(true);
        $dbData = $this->fetchDbDataChunked($empData['date_from'], $empData['date_to'], $empAccountId);
        $this->info("Fetched {$dbData['total']} records in " . $this->elapsed($dbTime));
        $this->displayDbSummary($dbData);

        $this->newLine();
        $this->info('=== COMPARISON ===');
        $discrepancies = $this->compare($empData, $dbData);
        $this->displayComparison($empData, $dbData, $discrepancies);

        if ($shouldExport) {
            $this->exportReport($empData, $dbData, $discrepancies);
        }

        $this->info('Total time: ' . $this->elapsed($startTime));

        return 0;
    }

    private function elapsed(float $start): string
    {
        return round(microtime(true) - $start, 2) . 's';
    }

    private function parseEmpCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['transactions' => [], 'total' => 0];
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return ['transactions' => [], 'total' => 0];
        }

        $headers = array_map('trim', $headers);

        // Pre-map header indices for speed
        $idx = [];
        foreach (['Unique ID', 'Status', 'Type', 'Amount (with decimal mark per currency exponent)',
                   'DateTime (UTC)', 'Terminal', 'IBAN / Account No', 'BIC / SWIFT code',
                   'Customer name', 'Card Holder', 'Merchant Transaction id',
                   'Funds status', 'Currency'] as $col) {
            $idx[$col] = array_search($col, $headers);
        }

        $transactions = [];
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

        while (($row = fgetcsv($handle)) !== false) {
            $uniqueId = isset($idx['Unique ID']) && $idx['Unique ID'] !== false
                ? trim($row[$idx['Unique ID']] ?? '')
                : '';

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

            $transactions[$uniqueId] = [
                'unique_id' => $uniqueId,
                'status' => $mappedStatus,
                'raw_status' => $status,
                'type' => $type,
                'amount' => $amount,
                'currency' => trim($row[$idx['Currency']] ?? 'EUR'),
                'date_time' => $dateTime,
                'terminal' => $terminal,
                'iban' => trim($row[$idx['IBAN / Account No']] ?? ''),
                'bic' => trim($row[$idx['BIC / SWIFT code']] ?? ''),
                'customer_name' => $customerName,
                'merchant_tx_id' => trim($row[$idx['Merchant Transaction id']] ?? ''),
                'funds_status' => trim($row[$idx['Funds status']] ?? ''),
            ];

            $total++;
        }

        fclose($handle);

        return [
            'transactions' => $transactions,
            'total' => $total,
            'status_counts' => $statusCounts,
            'terminals' => $terminals,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amounts' => $totalAmount,
        ];
    }

    private function fetchDbDataChunked(string $dateFrom, string $dateTo, ?string $empAccountId): array
    {
        $transactions = [];
        $statusCounts = [];
        $totalAmount = [
            'approved' => 0,
            'chargebacked' => 0,
            'error' => 0,
            'declined' => 0,
            'pending' => 0,
        ];
        $totalRows = 0;

        $query = DB::table('billing_attempts')
            ->where('created_at', '>=', $dateFrom . ' 00:00:00')
            ->where('created_at', '<=', $dateTo . ' 23:59:59');

        if ($empAccountId) {
            $query->where('emp_account_id', (int) $empAccountId);
        }

        $query->select([
                'id', 'unique_id', 'status', 'amount', 'currency',
                'created_at', 'emp_created_at', 'chargebacked_at',
                'chargeback_reason_code', 'bic', 'emp_account_id',
            ])
            ->orderBy('id')
            ->chunk(self::DB_CHUNK_SIZE, function ($rows) use (
                &$transactions, &$statusCounts, &$totalAmount, &$totalRows
            ) {
                foreach ($rows as $row) {
                    $totalRows++;
                    $status = $row->status;
                    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

                    if (isset($totalAmount[$status])) {
                        $totalAmount[$status] += (float) $row->amount;
                    }

                    if ($row->unique_id) {
                        $transactions[$row->unique_id] = [
                            'id' => $row->id,
                            'unique_id' => $row->unique_id,
                            'status' => $status,
                            'amount' => (float) $row->amount,
                            'currency' => $row->currency,
                            'created_at' => $row->created_at,
                            'emp_created_at' => $row->emp_created_at,
                            'chargebacked_at' => $row->chargebacked_at,
                            'cb_code' => $row->chargeback_reason_code,
                            'bic' => $row->bic,
                            'emp_account_id' => $row->emp_account_id,
                        ];
                    }
                }

                $this->output->write('.');
            });

        $this->newLine();

        return [
            'transactions' => $transactions,
            'total' => $totalRows,
            'status_counts' => $statusCounts,
            'amounts' => $totalAmount,
        ];
    }

    private function compare(array $empData, array $dbData): array
    {
        $discrepancies = [
            'in_emp_not_db' => [],
            'in_db_not_emp' => [],
            'status_mismatch' => [],
            'amount_mismatch' => [],
        ];

        $empSales = array_filter(
            $empData['transactions'],
            fn($t) => in_array($t['status'], ['approved', 'chargebacked', 'error'])
        );

        foreach ($empSales as $uid => $empTx) {
            if (!isset($dbData['transactions'][$uid])) {
                $discrepancies['in_emp_not_db'][] = $empTx;
            }
        }

        foreach ($dbData['transactions'] as $uid => $dbTx) {
            if (!isset($empData['transactions'][$uid])) {
                $discrepancies['in_db_not_emp'][] = $dbTx;
            }
        }

        foreach ($empSales as $uid => $empTx) {
            if (!isset($dbData['transactions'][$uid])) {
                continue;
            }

            $dbTx = $dbData['transactions'][$uid];

            if ($empTx['status'] !== $dbTx['status']) {
                $discrepancies['status_mismatch'][] = [
                    'unique_id' => $uid,
                    'emp_status' => $empTx['status'],
                    'db_status' => $dbTx['status'],
                    'emp_amount' => $empTx['amount'],
                    'db_amount' => $dbTx['amount'],
                    'emp_date' => $empTx['date_time'],
                    'db_date' => $dbTx['created_at'],
                ];
            }

            $amountDiff = abs($empTx['amount'] - $dbTx['amount']);
            if ($amountDiff > 0.01) {
                $discrepancies['amount_mismatch'][] = [
                    'unique_id' => $uid,
                    'emp_amount' => $empTx['amount'],
                    'db_amount' => $dbTx['amount'],
                    'diff' => $amountDiff,
                    'status' => $dbTx['status'],
                ];
            }
        }

        return $discrepancies;
    }

    private function displayEmpSummary(array $empData): void
    {
        $this->info('--- EMP Portal Data ---');
        $rows = [];
        foreach ($empData['status_counts'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Status', 'Count'], $rows);

        $this->info('EMP Volumes:');
        foreach ($empData['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $this->line("  {$status}: EUR " . number_format($amount, 2));
            }
        }
        $this->newLine();
    }

    private function displayDbSummary(array $dbData): void
    {
        $this->info('--- Our Database ---');
        $rows = [];
        foreach ($dbData['status_counts'] as $status => $count) {
            $rows[] = [$status, $count];
        }
        $this->table(['Status', 'Count'], $rows);

        $this->info('DB Volumes:');
        foreach ($dbData['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $this->line("  {$status}: EUR " . number_format($amount, 2));
            }
        }
        $this->newLine();
    }

    private function displayComparison(array $empData, array $dbData, array $discrepancies): void
    {
        $empSalesCount = count(array_filter(
            $empData['transactions'],
            fn($t) => in_array($t['status'], ['approved', 'chargebacked', 'error'])
        ));
        $dbCount = count($dbData['transactions']);

        $this->table(['Metric', 'EMP', 'Our DB', 'Diff'], [
            ['Sale Transactions', $empSalesCount, $dbCount, $empSalesCount - $dbCount],
            ['Approved',
                $empData['status_counts']['approved'] ?? 0,
                $dbData['status_counts']['approved'] ?? 0,
                ($empData['status_counts']['approved'] ?? 0) - ($dbData['status_counts']['approved'] ?? 0)],
            ['Chargebacked',
                $empData['status_counts']['chargebacked'] ?? 0,
                $dbData['status_counts']['chargebacked'] ?? 0,
                ($empData['status_counts']['chargebacked'] ?? 0) - ($dbData['status_counts']['chargebacked'] ?? 0)],
            ['Errors',
                $empData['status_counts']['error'] ?? 0,
                $dbData['status_counts']['error'] ?? 0,
                ($empData['status_counts']['error'] ?? 0) - ($dbData['status_counts']['error'] ?? 0)],
            ['CB Events (EMP only)',
                $empData['status_counts']['chargeback_event'] ?? 0, '-', '-'],
        ]);

        $this->newLine();

        $inEmpNotDb = count($discrepancies['in_emp_not_db']);
        $inDbNotEmp = count($discrepancies['in_db_not_emp']);
        $statusMismatch = count($discrepancies['status_mismatch']);
        $amountMismatch = count($discrepancies['amount_mismatch']);

        $this->info('--- Discrepancies ---');
        $this->table(['Type', 'Count'], [
            ['In EMP but not in our DB', $inEmpNotDb],
            ['In our DB but not in EMP', $inDbNotEmp],
            ['Status mismatch', $statusMismatch],
            ['Amount mismatch (>0.01)', $amountMismatch],
        ]);

        if ($statusMismatch > 0) {
            $this->warn("Status mismatches (first 20):");
            $sample = array_slice($discrepancies['status_mismatch'], 0, 20);
            $this->table(
                ['Unique ID', 'EMP Status', 'DB Status', 'EMP Amount', 'DB Amount'],
                array_map(fn($d) => [
                    substr($d['unique_id'], 0, 16) . '...',
                    $d['emp_status'],
                    $d['db_status'],
                    'EUR ' . number_format($d['emp_amount'], 2),
                    'EUR ' . number_format($d['db_amount'], 2),
                ], $sample)
            );
        }

        if ($amountMismatch > 0) {
            $this->warn("Amount mismatches (first 10):");
            $sample = array_slice($discrepancies['amount_mismatch'], 0, 10);
            $this->table(
                ['Unique ID', 'EMP Amount', 'DB Amount', 'Diff'],
                array_map(fn($d) => [
                    substr($d['unique_id'], 0, 16) . '...',
                    'EUR ' . number_format($d['emp_amount'], 2),
                    'EUR ' . number_format($d['db_amount'], 2),
                    'EUR ' . number_format($d['diff'], 2),
                ], $sample)
            );
        }

        $this->newLine();
        $totalIssues = $inEmpNotDb + $inDbNotEmp + $statusMismatch + $amountMismatch;
        if ($totalIssues === 0) {
            $this->info('RECONCILIATION PASSED - No discrepancies found');
        } else {
            $this->warn("RECONCILIATION ISSUES - {$totalIssues} total discrepancies found");
        }
        $this->newLine();
    }

    private function exportReport(array $empData, array $dbData, array $discrepancies): void
    {
        $dateFrom = $empData['date_from'];
        $dateTo = $empData['date_to'];
        $timestamp = date('Ymd_His');
        $basePath = self::STORAGE_PATH . "/{$dateFrom}_{$dateTo}";

        $summary = $this->buildSummaryReport($empData, $dbData, $discrepancies);
        $summaryPath = "{$basePath}/summary_{$dateFrom}_to_{$dateTo}_{$timestamp}.txt";
        Storage::disk(self::STORAGE_DISK)->put($summaryPath, $summary);
        $this->info("Summary saved: {$summaryPath}");

        if ($this->hasDiscrepancies($discrepancies)) {
            $csv = $this->buildDiscrepancyCsv($discrepancies);
            $csvPath = "{$basePath}/discrepancies_{$dateFrom}_to_{$dateTo}_{$timestamp}.csv";
            Storage::disk(self::STORAGE_DISK)->put($csvPath, $csv);
            $this->info("Discrepancies saved: {$csvPath}");
        }

        $originalPath = "{$basePath}/emp_export_{$dateFrom}_to_{$dateTo}.csv";
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

    private function buildSummaryReport(array $empData, array $dbData, array $discrepancies): string
    {
        $lines = [];
        $lines[] = '=== EMP RECONCILIATION REPORT ===';
        $lines[] = 'Generated: ' . now()->toDateTimeString();
        $lines[] = "Period: {$empData['date_from']} to {$empData['date_to']}";
        $lines[] = 'Terminals: ' . implode(', ', $empData['terminals']);
        $lines[] = '';
        $lines[] = '--- EMP PORTAL ---';
        foreach ($empData['status_counts'] as $status => $count) {
            $lines[] = "  {$status}: {$count}";
        }
        $lines[] = '';
        $lines[] = 'EMP Volumes:';
        foreach ($empData['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $lines[] = "  {$status}: EUR " . number_format($amount, 2);
            }
        }
        $lines[] = '';
        $lines[] = '--- OUR DATABASE ---';
        foreach ($dbData['status_counts'] as $status => $count) {
            $lines[] = "  {$status}: {$count}";
        }
        $lines[] = '';
        $lines[] = 'DB Volumes:';
        foreach ($dbData['amounts'] as $status => $amount) {
            if ($amount > 0) {
                $lines[] = "  {$status}: EUR " . number_format($amount, 2);
            }
        }
        $lines[] = '';
        $lines[] = '--- DISCREPANCIES ---';
        $lines[] = 'In EMP not in DB: ' . count($discrepancies['in_emp_not_db']);
        $lines[] = 'In DB not in EMP: ' . count($discrepancies['in_db_not_emp']);
        $lines[] = 'Status mismatch: ' . count($discrepancies['status_mismatch']);
        $lines[] = 'Amount mismatch: ' . count($discrepancies['amount_mismatch']);

        $total = count($discrepancies['in_emp_not_db'])
            + count($discrepancies['in_db_not_emp'])
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

        foreach ($discrepancies['in_emp_not_db'] as $tx) {
            fputcsv($handle, [
                'IN_EMP_NOT_DB', $tx['unique_id'], $tx['status'], '-',
                $tx['amount'], '-', $tx['customer_name'] . ' | ' . $tx['iban'],
            ]);
        }

        foreach ($discrepancies['in_db_not_emp'] as $tx) {
            fputcsv($handle, [
                'IN_DB_NOT_EMP', $tx['unique_id'], '-', $tx['status'],
                '-', $tx['amount'], 'Account: ' . $tx['emp_account_id'],
            ]);
        }

        foreach ($discrepancies['status_mismatch'] as $d) {
            fputcsv($handle, [
                'STATUS_MISMATCH', $d['unique_id'], $d['emp_status'], $d['db_status'],
                $d['emp_amount'], $d['db_amount'], '',
            ]);
        }

        foreach ($discrepancies['amount_mismatch'] as $d) {
            fputcsv($handle, [
                'AMOUNT_MISMATCH', $d['unique_id'], $d['status'], $d['status'],
                $d['emp_amount'], $d['db_amount'], 'Diff: ' . number_format($d['diff'], 2),
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
            || !empty($discrepancies['in_db_not_emp'])
            || !empty($discrepancies['status_mismatch'])
            || !empty($discrepancies['amount_mismatch']);
    }
}
