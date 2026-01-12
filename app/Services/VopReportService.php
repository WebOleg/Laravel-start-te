<?php

namespace App\Services;

use App\Models\Upload;
use App\Models\Debtor;
use App\Models\VopLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class VopReportService
{
    private const REPORTS_DIR = 'vop-reports';

    /**
     * Generate a human-readable VOP report for an upload
     */
    public function generateReport(int $uploadId): string
    {
        $upload = Upload::findOrFail($uploadId);

        $debtors = Debtor::where('upload_id', $uploadId)
            ->with('vopLogs')
            ->orderBy('id')
            ->get();

        $totalDebtors = $debtors->count();
        $validDebtors = $debtors->where('validation_status', 'valid')->count();
        $invalidDebtors = $debtors->where('validation_status', 'invalid')->count();

        $vopProcessed = $debtors->filter(fn($d) => $d->vop_status !== null)->count();
        $vopVerified = $debtors->where('vop_status', 'verified')->count();
        $vopFailed = $debtors->where('vop_status', 'failed')->count();

        $bavSelected = $debtors->where('bav_selected', true)->count();
        $bavVerified = $debtors->where('bav_verified', true)->count();

        $cacheHits = 0;
        $cacheMisses = 0;

        $startTime = null;
        $endTime = null;

        $report = $this->buildReportHeader($upload, $totalDebtors);
        $report .= $this->buildValidationSection($validDebtors, $invalidDebtors);
        $report .= $this->buildVopSection($vopProcessed, $vopVerified, $vopFailed, $bavSelected, $bavVerified);

        // Process each debtor
        foreach ($debtors as $debtor) {
            if ($debtor->vop_status === null) {
                continue;
            }

            $vopLog = $debtor->vopLogs()->latest()->first();

            if ($vopLog) {
                if ($startTime === null || $vopLog->created_at < $startTime) {
                    $startTime = $vopLog->created_at;
                }
                if ($endTime === null || $vopLog->created_at > $endTime) {
                    $endTime = $vopLog->created_at;
                }

                if (str_contains(strtolower($vopLog->raw_response ?? ''), 'cache hit')) {
                    $cacheHits++;
                } else {
                    $cacheMisses++;
                }
            }

            $report .= $this->buildDebtorSection($debtor, $vopLog);
        }

        $report .= $this->buildSummarySection(
            $uploadId,
            $totalDebtors,
            $validDebtors,
            $invalidDebtors,
            $vopProcessed,
            $vopVerified,
            $vopFailed,
            $bavSelected,
            $bavVerified,
            $cacheHits,
            $cacheMisses,
            $startTime,
            $endTime
        );

        // Save report to storage
        $filename = $this->saveReport($uploadId, $upload->filename, $report);

        Log::info('VOP report generated', [
            'upload_id' => $uploadId,
            'report_file' => $filename,
            'debtors' => $totalDebtors,
            'vop_verified' => $vopVerified,
            'bav_verified' => $bavVerified,
        ]);

        return $filename;
    }

    private function buildReportHeader(Upload $upload, int $totalDebtors): string
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        return <<<EOT
# VOP Processing Report - Upload ID: {$upload->id}
# Generated: {$timestamp}
# Original File: {$upload->filename}
# File Size: {$upload->file_size} bytes
# Total Records: {$totalDebtors}

================================================================================
FILE UPLOAD
================================================================================
Upload ID: {$upload->id}
Original File: {$upload->filename}
S3 Path: {$upload->file_path}
File Size: {$upload->file_size} bytes
Uploaded: {$upload->created_at->format('Y-m-d H:i:s')}


EOT;
    }

    private function buildValidationSection(int $valid, int $invalid): string
    {
        $total = $valid + $invalid;
        $validPct = $total > 0 ? round(($valid / $total) * 100, 1) : 0;

        return <<<EOT
================================================================================
VALIDATION PHASE
================================================================================
Total Records: {$total}
✓ Valid: {$valid} ({$validPct}%)
✗ Invalid: {$invalid}


EOT;
    }

    private function buildVopSection(
        int $processed,
        int $verified,
        int $failed,
        int $bavSelected,
        int $bavVerified
    ): string {
        $bavEnabled = config('services.iban.bav_enabled', false) ? 'ENABLED' : 'DISABLED';
        $bavPct = $processed > 0 ? round(($bavSelected / $processed) * 100, 1) : 0;

        return <<<EOT
================================================================================
VOP VERIFICATION PHASE
================================================================================
Total Processed: {$processed}
✓ Verified: {$verified}
✗ Failed: {$failed}

BAV Status: {$bavEnabled}
BAV Selected: {$bavSelected} ({$bavPct}% of processed)
BAV Verified: {$bavVerified}


================================================================================
DETAILED RESULTS
================================================================================

EOT;
    }

    private function buildDebtorSection(Debtor $debtor, ?VopLog $vopLog): string
    {
        $status = $debtor->vop_status === 'verified' ? '✓' : '✗';
        $bavBadge = $debtor->bav_selected ? ' [BAV]' : '';
        $bavVerifiedBadge = $debtor->bav_verified ? ' [BAV ✓]' : '';

        $section = <<<EOT
--------------------------------------------------------------------------------
Debtor #{$debtor->id} | {$debtor->debtor_name}{$bavBadge}{$bavVerifiedBadge}
--------------------------------------------------------------------------------
IBAN: {$debtor->iban}
Status: {$status} {$debtor->vop_status}

EOT;

        if ($vopLog) {
            $section .= <<<EOT
Bank: {$vopLog->bank_name}
BIC: {$vopLog->bic}
SEPA SDD: {$vopLog->sepa_sdd_support}
Score: {$vopLog->final_score}/100

Score Breakdown:
  • IBAN Valid: {$vopLog->score_iban_valid}
  • Bank Identified: {$vopLog->score_bank_identified}
  • SEPA SDD: {$vopLog->score_sepa_sdd}
  • Country Supported: {$vopLog->score_country_supported}
  • Name Match: {$vopLog->score_name_match}

EOT;

            if ($debtor->bav_selected && $vopLog->name_match_result) {
                $section .= "Name Match Result: {$vopLog->name_match_result}\n";
            }

            $section .= "Verified At: {$vopLog->created_at->format('Y-m-d H:i:s')}\n";
        }

        $section .= "\n";

        return $section;
    }

    private function buildSummarySection(
        int $uploadId,
        int $totalDebtors,
        int $validDebtors,
        int $invalidDebtors,
        int $vopProcessed,
        int $vopVerified,
        int $vopFailed,
        int $bavSelected,
        int $bavVerified,
        int $cacheHits,
        int $cacheMisses,
        $startTime,
        $endTime
    ): string {
        $processingTime = ($startTime && $endTime)
            ? $endTime->diffInSeconds($startTime)
            : 0;

        $successRate = $vopProcessed > 0
            ? round(($vopVerified / $vopProcessed) * 100, 1)
            : 0;

        $cacheHitRate = ($cacheHits + $cacheMisses) > 0
            ? round(($cacheHits / ($cacheHits + $cacheMisses)) * 100, 1)
            : 0;

        $avgScore = VopLog::where('upload_id', $uploadId)
            ->avg('vop_score');
        $avgScore = $avgScore ? round($avgScore, 1) : 0;

        $startTimeFormatted = $startTime ? $startTime->format('Y-m-d H:i:s') : 'N/A';
        $endTimeFormatted = $endTime ? $endTime->format('Y-m-d H:i:s') : 'N/A';

        return <<<EOT
================================================================================
SUMMARY STATISTICS
================================================================================
Processing Time: {$processingTime} seconds
Start: {$startTimeFormatted}
End: {$endTimeFormatted}

Validation Results:
  Total Records: {$totalDebtors}
  Valid: {$validDebtors}
  Invalid: {$invalidDebtors}

VOP Verification:
  Processed: {$vopProcessed}
  Verified: {$vopVerified} ({$successRate}% success rate)
  Failed: {$vopFailed}
  Average Score: {$avgScore}/100

BAV Verification:
  Selected for BAV: {$bavSelected}
  BAV Verified: {$bavVerified}
  BAV Config: " . (config('services.iban.bav_enabled') ? 'ENABLED' : 'DISABLED') . "
  BAV Sampling: " . config('services.iban.bav_sampling_percentage', 10) . "%

Cache Performance:
  Hits: {$cacheHits}
  Misses: {$cacheMisses}
  Hit Rate: {$cacheHitRate}%

================================================================================
END OF REPORT
================================================================================
EOT;
    }

    private function saveReport(int $uploadId, string $originalFilename, string $content): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);

        // Use direct file path
        $dir = storage_path('app/' . self::REPORTS_DIR);
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = "vop_report_upload_{$uploadId}_{$baseFilename}_{$timestamp}.txt";
        $fullPath = $dir . '/' . $filename;

        file_put_contents($fullPath, $content);

        return self::REPORTS_DIR . '/' . $filename;
    }

    /**
     * Get the path to a report file
     */
    public function getReportPath(string $filename): string
    {
        return Storage::path($filename);
    }

    /**
     * Get all reports for an upload
     */
    public function getReportsForUpload(int $uploadId): array
    {
        $files = Storage::files(self::REPORTS_DIR);

        return collect($files)
            ->filter(fn($file) => str_contains($file, "upload_{$uploadId}_"))
            ->sort()
            ->values()
            ->toArray();
    }
}
