<?php

/**
 * Backfill bav_verified_ibans table from existing vop_logs with bav_verified = true.
 * One-time command to populate the global BAV cache from historical data.
 */

namespace App\Console\Commands;

use App\Models\BavVerifiedIban;
use App\Services\IbanValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillBavVerifiedIbans extends Command
{
    protected $signature = 'bav:backfill-verified {--dry-run : Show what would be done without making changes}';
    protected $description = 'Backfill bav_verified_ibans from existing vop_logs with bav_verified=true';

    public function handle(IbanValidator $ibanValidator): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $records = DB::table('vop_logs')
            ->join('debtors', 'vop_logs.debtor_id', '=', 'debtors.id')
            ->whe('vop_logs.bav_verified', true)
            ->whereNotNull('debtors.iban')
            ->select([
                'debtors.iban',
                'debtors.first_name',
                'debtors.last_name',
                'debtors.upload_id',
                'vop_logs.name_match',
                'vop_logs.bic',
                'vop_logs.vop_score',
                'vop_logs.result',
                'vop_logs.updated_at as verified_at',
            ])
            ->get();

        $this->info("Found {$records->count()} BAV-verified records to backfill");

        $inserted = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($records->count());
        $bar->start();

        foreach ($records as $record) {
            $ibanHash = $ibanValidator->hash($ibanValidator->normalize($record->iban));
            $ibanMasked = $ibanValidator->mask($record->iban);
            $fullName = trim(($record->first_name ?? '') . ' ' . ($record->last_name ?? ''));

            if ($dryRun) {
                $this->line("  Would insert: {$ibanMasked} | {$record->name_match} | {$record->bic}");
                $inserted++;
                $bar->advance();
                continue;
            }

            try {
                BavVerifiedIban::updateOrCreate(
                    ['iban_hash' => $ibanHash],
                    [
                        'iban_masked' => $ibanMasked,
                        'full_name' => $fullName,
                        'name_match' => $record->name_match,
                        'bic' => $record->bic,
                        'bav_score' => $record->vop_score ?? 0,
                        'bav_result' => $record->result ?? 'verified',
                        'source' => BavVerifiedIban::SOURCE_UPLOAD_BAV,
                        'source_id' => $record->upload_id,
                        'verified_at' => $record->verified_at,
                    ]
                );
                $inserted++;
            } catch (\Exception $e) {
                $skipped++;
                $this->warn("  Skipped: {$ibanMasked} — {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(['Metric', 'Count'], [
            ['Total found', $records->count()],
            ['Inserted/Updated', $inserted],
            ['Skipped (errors)', $skipped],
        ]);

        return 0;
    }
}
