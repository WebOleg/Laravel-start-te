<?php

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use App\Traits\WithLogContext;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportChargebacksCommand extends Command
{
    use WithLogContext;

    protected $signature = 'chargebacks:export {--upload_id=} {--disk=local} {--reason-code= : Filter by chargeback reason code (e.g. XT73)} {--from= : Filter chargebacks from this date (Y-m-d)} {--to= : Filter chargebacks up to this date (Y-m-d)} {--date= : Filter chargebacks on a specific date (Y-m-d)} {--emp-account-id= : Filter by EMP account ID}';

    protected $description = 'Export chargebacks to CSV file';

    public function handle()
    {
        // Initialize the context
        $this->initLogContext();

        $uploadId = $this->option('upload_id');
        $disk = $this->option('disk');
        $reasonCode = $this->option('reason-code');
        $from = $this->option('from');
        $to = $this->option('to');
        $date = $this->option('date');
        $empAccountId = $this->option('emp-account-id');

        // Validate mutual exclusivity of --date vs --from/--to
        if ($date && ($from || $to)) {
            $this->error('Cannot use --date together with --from or --to. Use either --date for a single day, or --from/--to for a range.');
            return 1;
        }

        // Validate date formats
        if ($from && !$this->isValidDate($from)) {
            $this->error('Invalid --from date format. Expected Y-m-d (e.g. 2026-01-15)');
            return 1;
        }
        if ($to && !$this->isValidDate($to)) {
            $this->error('Invalid --to date format. Expected Y-m-d (e.g. 2026-01-15)');
            return 1;
        }
        if ($date && !$this->isValidDate($date)) {
            $this->error('Invalid --date format. Expected Y-m-d (e.g. 2026-01-15)');
            return 1;
        }

        // Build filename suffix
        $filenameParts = ['chargebacks_export'];
        if ($reasonCode) {
            $filenameParts[] = strtoupper($reasonCode);
        }
        if ($date) {
            $filenameParts[] = $date;
        } else {
            if ($from) {
                $filenameParts[] = 'from' . $from;
            }
            if ($to) {
                $filenameParts[] = 'to' . $to;
            }
        }
        if ($empAccountId) {
            $filenameParts[] = 'emp' . $empAccountId;
        }
        $filenameParts[] = date('Y-m-d_His');
        $filename = implode('_', $filenameParts) . '.csv';

        $this->info('Fetching chargebacks');

        $query = BillingAttempt::with('debtor:id,first_name,last_name,iban')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($uploadId) {
            $query->where('upload_id', $uploadId);
            $this->info('Filtering by upload_id: ' . $uploadId);
        }

        if ($reasonCode) {
            $query->where('chargeback_reason_code', strtoupper($reasonCode));
            $this->info('Filtering by reason code: ' . strtoupper($reasonCode));
        }

        if ($date) {
            $query->whereDate('chargebacked_at', $date);
            $this->info('Filtering by date: ' . $date);
        } else {
            if ($from) {
                $query->where('chargebacked_at', '>=', Carbon::parse($from)->startOfDay());
                $this->info('Filtering from: ' . $from);
            }
            if ($to) {
                $query->where('chargebacked_at', '<=', Carbon::parse($to)->endOfDay());
                $this->info('Filtering to: ' . $to);
            }
        }

        if ($empAccountId) {
            $query->where('emp_account_id', $empAccountId);
            $this->info('Filtering by EMP account ID: ' . $empAccountId);
        }

        $chargebacks = $query->get();

        if ($chargebacks->isEmpty()) {
            $this->warn('No chargebacks found');
            return 1;
        }

        $count = $chargebacks->count();
        $this->info('Found ' . $count . ' chargebacks');

        $csvContent = $this->generateCsv($chargebacks);

        Storage::disk($disk)->put($filename, $csvContent);

        $this->newLine();
        $this->info('Exported ' . $count . ' chargebacks');
        $this->info('Saved to disk: ' . $disk);
        $this->info('File: ' . $filename);
        $this->newLine();

        if ($disk === 's3') {
            $this->comment('File saved to S3/MinIO');
            $this->line('Filename: ' . $filename);
        } else {
            $path = Storage::disk($disk)->path($filename);
            $this->comment('Local file path:');
            $this->line($path);
            $this->newLine();
            $this->comment('To download from Docker:');
            $this->line('docker cp <container_name>:' . $path . ' ./chargebacks.csv');
        }

        $this->newLine();

        return 0;
    }

    private function generateCsv($chargebacks)
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, array(
            'Name',
            'IBAN',
            'Chargeback Code',
            'Chargeback Reason',
            'Unique ID',
            'Amount',
            'Currency',
            'Chargebacked At'
        ));

        foreach ($chargebacks as $chargeback) {
            $debtor = $chargeback->debtor;

            if ($debtor) {
                $name = trim($debtor->first_name . ' ' . $debtor->last_name);
                $iban = $debtor->iban;
            } else {
                $name = 'N/A';
                $iban = 'N/A';
            }

            $code = $chargeback->chargeback_reason_code;
            if (!$code) {
                $code = 'N/A';
            }

            $reason = $chargeback->chargeback_reason_description;
            if (!$reason) {
                $reason = 'N/A';
            }

            $uniqueId = $chargeback->unique_id;
            if (!$uniqueId) {
                $uniqueId = 'N/A';
            }

            $chargebackDate = 'N/A';
            if ($chargeback->chargebacked_at) {
                $chargebackDate = $chargeback->chargebacked_at->format('Y-m-d H:i:s');
            }

            fputcsv($handle, array(
                $name,
                $iban,
                $code,
                $reason,
                $uniqueId,
                $chargeback->amount,
                $chargeback->currency,
                $chargebackDate
            ));
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value;
    }
}
