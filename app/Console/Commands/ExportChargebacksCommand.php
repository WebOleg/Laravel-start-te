<?php

namespace App\Console\Commands;

use App\Models\BillingAttempt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportChargebacksCommand extends Command
{
    protected $signature = 'chargebacks:export {--upload_id=} {--disk=local}';

    protected $description = 'Export chargebacks to CSV file';

    public function handle()
    {
        $uploadId = $this->option('upload_id');
        $disk = $this->option('disk');
        $filename = 'chargebacks_export_' . date('Y-m-d_His') . '.csv';

        $this->info('Fetching chargebacks');

        $query = BillingAttempt::with('debtor:id,first_name,last_name,iban')
            ->where('status', BillingAttempt::STATUS_CHARGEBACKED);

        if ($uploadId) {
            $query->where('upload_id', $uploadId);
            $this->info('Filtering by upload_id: ' . $uploadId);
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
}
