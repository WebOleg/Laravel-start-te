<?php

/**
 * EmpReconcileReport - Compare EMP portal CSV export with our billing_attempts database.
 * Identifies discrepancies in transaction counts, statuses, and amounts.
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

        $this->info('Reading EMP CSV export...');
        $empData = $this->parseEmpCsv($filePath);

        if (empty($empData['transactions'])) {
            $this->error('No transactions found in CSV');
            return 1;
        }

        $this->info("Parsed {$empData['total']} rows from EMP CSV");
        $this->info("Date range: {$empData['date_from']} to {$empData['date_to']}");
        $this->info("Terminals: " . implode(', ', $empData['terminals']));
        $this->newLine();

        $this->displayEmpSummary($empData);

        $this->info('Fetching our database records...');
        $dbData = $this->fetchDbData($empData['date_from'], $empData['date_to'], $empAccountId);
        $this->displayDbSummary($dbData);

        $this->newLine();
        $this->info('=== COMPARISON ===');
        $discrepancies = $this->compare($empData, $dbData);
        $this->displayComparison($empData, $dbData, $discrepancies);

        if ($shouldExport && !empty($discrepancies)) {
            $this->exportDiscrepancies($discrepancies, $empData['date_from'], $empData['date_to']);
        }

        return 0;
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
            if (count($row) < count($headers)) {
                continue;
            }

            $data = array_combine($headers, $row);
            $uniqueId = trim($data['Unique ID'] ?? '');

            if (empty($uniqueId)) {
                continue;
            }

            $status = trim($data['Status'] ?? '');
            $type = trim($data['Type'] ?? '');
            $amount = (float) str_replace(',', '.', $data['Amount (with decimal mark per currency exponent)'] ?? '0');
            $dateTime = trim($data['DateTime (UTC)'] ?? '');
            $terminal = trim($data['Terminal'] ?? '');
            $iban = trim($data['IBAN / Account No'] ?? '');
            $bic = trim($data['BIC / SWIFT code'] ?? '');
            $customerName = trim($data['Customer name'] ?? $data['Card Holder'] ?? '');
            $merchantTxId = trim($data['Merchant Transaction id'] ?? '');
            $fundsStatus = trim($data['Funds status'] ?? '');

            $mappedStatus = self::EMP_STATUS_MAP["{$status}|{$type}"] ?? "{$status}|{$type}";

            $statusCounts[$mappedStatus] = ($statusCounts[$mappedStatus] ?? 0) + 1;

            if (isset($totalAmount[$mappedStatus])) {
                $totalAmount[$mappedStatus] += $amount;
            }

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

            $transactions[$uniqueId] = [
                'unique_id' => $uniqueId,
                'status' => $mappedStatus,
                'raw_status' => $status,
                'type' => $type,
                'amount' => $amount,
                'currency' => trim($data['Currency'] ?? 'EUR'),
                'date_time' => $dateTime,
                'terminal' => $terminal,
                'iban' => $iban,
                'bic' => $bic,
                'customer_name' => $customerName,
                'merchant_tx_id' => $merchantTxId,
                'funds_status' => $fundsStatus,
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

    private function fetchDbData(string $dateFrom, string $dateTo, ?string $empAccountId): array
    {
        $query = DB::table('billing_attempts')
            ->where('created_at', '>=', $dateFrom . ' 00:00:00')
            ->where('created_at', '<=', $dateTo . ' 23:59:59');

        if ($empAccountId) {
            $query->where('emp_account_id', (int) $empAccountId);
        }

        $rows = $query->get([
            'id', 'unique_id', 'status', 'amount', 'currency',
            'created_at', 'emp_created_at', 'chargebacked_at',
            'chargeback_reason_code', 'chargeback_reason_description',
            'bic', 'emp_account_id',
        ]);

        $transactions = [];
        $statusCounts = [];
        $totalAmount = [
            'approved' => 0,
            'chargebacked' => 0,
            'error' => 0,
            'declined' => 0,
            'pending' => 0,
        ];

        foreach ($rows as $row) {
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

        return [
            'transactions' => $transactions,
            'total' => count($rows),
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
            $empStatus = $empTx['status'];
            $dbStatus = $dbTx['status'];

            if ($empStatus !== $dbStatus) {
                $discrepancies['status_mismatch'][] = [
                    'unique_id' => $uid,
                    'emp_status' => $empStatus,
                    'db_status' => $dbStatus,
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
                    'status' => $dbStatus,
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
            [
                'Sale Transactions',
                $empSalesCount,
                $dbCount,
                $empSalesCount - $dbCount,
            ],
            [
                'Approved',
                $empData['status_counts']['approved'] ?? 0,
                $dbData['status_counts']['approved'] ?? 0,
                ($empData['status_counts']['approved'] ?? 0) - ($dbData['status_counts']['approved'] ?? 0),
            ],
            [
                'Chargebacked',
                $empData['status_counts']['chargebacked'] ?? 0,
                $dbData['status_counts']['chargebacked'] ?? 0,
                ($empData['status_counts']['chargebacked'] ?? 0) - ($dbData['status_counts']['chargebacked'] ?? 0),
            ],
            [
                'Errors',
                $empData['status_counts']['error'] ?? 0,
                $dbData['status_counts']['error'] ?? 0,
                ($empData['status_counts']['error'] ?? 0) - ($dbData['status_counts']['error'] ?? 0),
            ],
            [
                'CB Events (EMP only)',
                $empData['status_counts']['chargeback_event'] ?? 0,
                '-',
                '-',
            ],
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

    private function exportDiscrepancies(array $discrepancies, string $dateFrom, string $dateTo): void
    {
        $filename = "emp_reconcile_{$dateFrom}_{$dateTo}_" . date('His') . '.csv';
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'Type', 'Unique ID', 'EMP Status', 'DB Status',
            'EMP Amount', 'DB Amount', 'Details',
        ]);

        foreach ($discrepancies['in_emp_not_db'] as $tx) {
            fputcsv($handle, [
                'IN_EMP_NOT_DB',
                $tx['unique_id'],
                $tx['status'],
                '-',
                $tx['amount'],
                '-',
                $tx['customer_name'] . ' | ' . $tx['iban'],
            ]);
        }

        foreach ($discrepancies['in_db_not_emp'] as $tx) {
            fputcsv($handle, [
                'IN_DB_NOT_EMP',
                $tx['unique_id'],
                '-',
                $tx['status'],
                '-',
                $tx['amount'],
                'Account: ' . $tx['emp_account_id'],
            ]);
        }

        foreach ($discrepancies['status_mismatch'] as $d) {
            fputcsv($handle, [
                'STATUS_MISMATCH',
                $d['unique_id'],
                $d['emp_status'],
                $d['db_status'],
                $d['emp_amount'],
                $d['db_amount'],
                '',
            ]);
        }

        foreach ($discrepancies['amount_mismatch'] as $d) {
            fputcsv($handle, [
                'AMOUNT_MISMATCH',
                $d['unique_id'],
                $d['status'],
                $d['status'],
                $d['emp_amount'],
                $d['db_amount'],
                'Diff: ' . number_format($d['diff'], 2),
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('local')->put($filename, $content);
        $path = Storage::disk('local')->path($filename);

        $this->info("Discrepancies exported to: {$path}");
        $this->line("docker cp <container>:{$path} ./{$filename}");
    }
}
