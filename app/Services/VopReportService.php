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

    // Score range constants
    private const SCORE_VERIFIED = 80;      // 80-100
    private const SCORE_LIKELY = 60;        // 60-79
    private const SCORE_INCONCLUSIVE = 40;  // 40-59
    private const SCORE_MISMATCH = 20;      // 20-39
    // 0-19 = REJECTED

    /**
     * Generate VOP CSV report for an upload
     */
    public function generateReport(int $uploadId): string
    {
        $upload = Upload::findOrFail($uploadId);

        $debtors = Debtor::where('upload_id', $uploadId)
            ->with('vopLogs')
            ->orderBy('id')
            ->get();

        $totalDebtors = $debtors->count();
        $vopVerified = $debtors->where('vop_status', 'verified')->count();
        $bavVerified = $debtors->where('bav_verified', true)->count();

        // Generate CSV report only
        $csvFilename = $this->generateTabularReport($uploadId, $upload, $debtors);

        Log::channel('bav')->info('BAV CSV report generated', [
            'upload_id' => $uploadId,
            'csv_report' => $csvFilename,
            'debtors' => $totalDebtors,
            'vop_verified' => $vopVerified,
            'bav_verified' => $bavVerified,
        ]);

        return $csvFilename;
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

        $filename = "vop_report_upload_{$uploadId}_{$baseFilename}_{$timestamp}.txt";
        $path = self::REPORTS_DIR . '/' . $filename;

        // Save to S3
        Storage::disk('s3')->put($path, $content);

        return $path;
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
        $files = Storage::disk('s3')->files(self::REPORTS_DIR);

        return collect($files)
            ->filter(fn($file) => str_contains($file, "upload_{$uploadId}_"))
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Get score category label and symbol
     */
    private function getScoreCategory(int $score): array
    {
        if ($score >= self::SCORE_VERIFIED) {
            return ['label' => 'VERIFIED', 'symbol' => '✓', 'desc' => 'High Confidence'];
        } elseif ($score >= self::SCORE_LIKELY) {
            return ['label' => 'LIKELY_VERIFIED', 'symbol' => '≈', 'desc' => 'Medium Confidence'];
        } elseif ($score >= self::SCORE_INCONCLUSIVE) {
            return ['label' => 'INCONCLUSIVE', 'symbol' => '?', 'desc' => 'Low Confidence'];
        } elseif ($score >= self::SCORE_MISMATCH) {
            return ['label' => 'MISMATCH', 'symbol' => '⚠', 'desc' => 'Poor Match'];
        } else {
            return ['label' => 'REJECTED', 'symbol' => '✗', 'desc' => 'Critical Failure'];
        }
    }

    /**
     * Generate BAV-specific report with score categorization
     */
    private function generateBavReport(int $uploadId, Upload $upload, $debtors): string
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $bavDebtors = $debtors->where('bav_selected', true);

        // Categorize by score
        $verified = [];
        $likelyVerified = [];
        $inconclusive = [];
        $mismatch = [];
        $rejected = [];

        foreach ($bavDebtors as $debtor) {
            $vopLog = $debtor->vopLogs()->latest()->first();
            if (!$vopLog) continue;

            $score = $vopLog->vop_score ?? 0;

            if ($score >= self::SCORE_VERIFIED) {
                $verified[] = ['debtor' => $debtor, 'log' => $vopLog];
            } elseif ($score >= self::SCORE_LIKELY) {
                $likelyVerified[] = ['debtor' => $debtor, 'log' => $vopLog];
            } elseif ($score >= self::SCORE_INCONCLUSIVE) {
                $inconclusive[] = ['debtor' => $debtor, 'log' => $vopLog];
            } elseif ($score >= self::SCORE_MISMATCH) {
                $mismatch[] = ['debtor' => $debtor, 'log' => $vopLog];
            } else {
                $rejected[] = ['debtor' => $debtor, 'log' => $vopLog];
            }
        }

        $totalBav = $bavDebtors->count();
        $allVopLogs = VopLog::whereIn('debtor_id', $bavDebtors->pluck('id'))->get();
        $avgScore = $allVopLogs->count() > 0 ? round($allVopLogs->avg('vop_score'), 1) : 0;

        // Calculate counts and percentages before heredoc
        $verifiedCount = count($verified);
        $likelyCount = count($likelyVerified);
        $inconclusiveCount = count($inconclusive);
        $mismatchCount = count($mismatch);
        $rejectedCount = count($rejected);

        $verifiedPct = round(($verifiedCount / max($totalBav, 1)) * 100, 1);
        $likelyPct = round(($likelyCount / max($totalBav, 1)) * 100, 1);
        $inconclusivePct = round(($inconclusiveCount / max($totalBav, 1)) * 100, 1);
        $mismatchPct = round(($mismatchCount / max($totalBav, 1)) * 100, 1);
        $rejectedPct = round(($rejectedCount / max($totalBav, 1)) * 100, 1);

        $bavCost = number_format($totalBav * 0.10, 2);

        $report = <<<EOT
# VOP BAV VERIFICATION - DETAILED RESULTS BY SCORE RANGE
# Upload ID: {$upload->id}
# Generated: {$timestamp}
# Original File: {$upload->filename}
# BAV Debtors: {$totalBav}

================================================================================
SCORING SYSTEM EXPLANATION
================================================================================

VOP Score Components (Total: 100 points):
  • IBAN Valid:           20 points (Checksum validation passed)
  • Bank Identified:      25 points (Bank name and BIC code retrieved)
  • SEPA SDD Support:     25 points (Bank supports SEPA Direct Debit)
  • Country Supported:    15 points (Country in SEPA zone)
  • BAV Name Match:       15 points (Account holder name matches)
  ────────────────────────────────
  TOTAL:                 100 points

Score Ranges & Results:
  ✓ 80-100: VERIFIED        (High confidence - Ready for processing)
  ≈ 60-79:  LIKELY_VERIFIED (Medium confidence - Consider for processing)
  ? 40-59:  INCONCLUSIVE    (Low confidence - Manual review needed)
  ⚠ 20-39:  MISMATCH        (Poor match - High risk)
  ✗ 0-19:   REJECTED        (Critical failure - Do not process)

================================================================================
BAV SUMMARY
================================================================================
Total BAV Verifications: {$totalBav}
Average Score: {$avgScore}/100
Cost: €{$bavCost} ({$totalBav} × €0.10)

Score Distribution:
  ✓ VERIFIED (80-100):       {$verifiedCount} debtors ({$verifiedPct}%)
  ≈ LIKELY_VERIFIED (60-79):  {$likelyCount} debtors ({$likelyPct}%)
  ? INCONCLUSIVE (40-59):     {$inconclusiveCount} debtors ({$inconclusivePct}%)
  ⚠ MISMATCH (20-39):         {$mismatchCount} debtors ({$mismatchPct}%)
  ✗ REJECTED (0-19):          {$rejectedCount} debtors ({$rejectedPct}%)


EOT;

        // VERIFIED section
        $report .= $this->buildBavCategorySection('VERIFIED', '✓', $verified, 'APPROVED FOR PROCESSING');

        // LIKELY_VERIFIED section
        $report .= $this->buildBavCategorySection('LIKELY_VERIFIED', '≈', $likelyVerified, 'CONSIDER FOR PROCESSING (Manual review recommended)');

        // INCONCLUSIVE section
        $report .= $this->buildBavCategorySection('INCONCLUSIVE', '?', $inconclusive, 'MANUAL REVIEW REQUIRED');

        // MISMATCH section
        $report .= $this->buildBavCategorySection('MISMATCH', '⚠', $mismatch, 'HIGH RISK - Manual review required');

        // REJECTED section
        $report .= $this->buildBavCategorySection('REJECTED', '✗', $rejected, 'DO NOT PROCESS');

        $report .= "\n" . $this->buildBavFooter($bavDebtors);

        // Save BAV report
        return $this->saveBavReport($uploadId, $upload->filename, $report);
    }

    /**
     * Build BAV category section
     */
    private function buildBavCategorySection(string $category, string $symbol, array $items, string $recommendation): string
    {
        $count = count($items);

        $section = <<<EOT

################################################################################
# {$symbol} {$category}
################################################################################

Total in this category: {$count} debtors
Recommendation: {$recommendation}


EOT;

        if ($count === 0) {
            $section .= "[No debtors in this category]\n\n";
            return $section;
        }

        foreach ($items as $item) {
            $debtor = $item['debtor'];
            $vopLog = $item['log'];

            $score = $vopLog->vop_score ?? 0;
            $maskedIban = substr($vopLog->iban, 0, 4) . '****' . substr($vopLog->iban, -4);
            $nameMatch = $vopLog->name_match ?? 'N/A';
            $nameMatchScore = $vopLog->name_match_score ?? 0;

            $section .= <<<EOT
--------------------------------------------------------------------------------
Debtor #{$debtor->id} | {$debtor->debtor_name} | Score: {$score}/100 | {$symbol} {$category}
--------------------------------------------------------------------------------
IBAN: {$maskedIban}
Bank: {$vopLog->bank_name} ({$vopLog->bic})
Country: {$vopLog->country}
Upload ID: {$debtor->upload_id}
Verified At: {$vopLog->created_at->format('Y-m-d H:i:s')}

Score Breakdown:
  ✓ IBAN Valid:        {$vopLog->score_iban_valid}/20
  ✓ Bank Identified:   {$vopLog->score_bank_identified}/25
  ✓ SEPA SDD:          {$vopLog->score_sepa_sdd}/25
  ✓ Country:           {$vopLog->score_country_supported}/15
  • BAV Name Match:    {$vopLog->score_name_match}/15
  ────────────────────────────
  TOTAL SCORE:        {$score}/100 {$symbol} {$category}

BAV Results:
  • Name Match: {$nameMatch}
  • Name Match Score: {$nameMatchScore}/100


EOT;
        }

        return $section;
    }

    /**
     * Build BAV footer with statistics
     */
    private function buildBavFooter($bavDebtors): string
    {
        $total = $bavDebtors->count();
        $exactMatch = $bavDebtors->filter(fn($d) => $d->vopLogs()->latest()->first()?->name_match === 'yes')->count();
        $partialMatch = $bavDebtors->filter(fn($d) => $d->vopLogs()->latest()->first()?->name_match === 'partial')->count();
        $noMatch = $bavDebtors->filter(fn($d) => $d->vopLogs()->latest()->first()?->name_match === 'no')->count();
        $unavailable = $bavDebtors->filter(fn($d) => in_array($d->vopLogs()->latest()->first()?->name_match, ['unavailable', 'error', null]))->count();

        return <<<EOT
================================================================================
BAV NAME MATCH STATISTICS
================================================================================
Total BAV Verifications: {$total}

Name Match Results:
  • Exact Match:           {$exactMatch} debtors (" . round(($exactMatch / max($total, 1)) * 100, 1) . "%)
  • Partial Match:         {$partialMatch} debtors (" . round(($partialMatch / max($total, 1)) * 100, 1) . "%)
  • No Match:              {$noMatch} debtors (" . round(($noMatch / max($total, 1)) * 100, 1) . "%)
  • Unavailable/Error:     {$unavailable} debtors (" . round(($unavailable / max($total, 1)) * 100, 1) . "%)

================================================================================
END OF BAV REPORT
================================================================================

EOT;
    }

    /**
     * Generate general VOP summary with percentages and failures
     */
    private function generateGeneralSummary(int $uploadId, Upload $upload, $debtors): string
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        $totalDebtors = $debtors->count();
        $validDebtors = $debtors->where('validation_status', 'valid')->count();
        $invalidDebtors = $debtors->where('validation_status', 'invalid')->count();

        $vopProcessed = $debtors->filter(fn($d) => $d->vop_verified_at !== null)->count();
        $vopVerified = $debtors->where('vop_status', 'verified')->count();
        $vopFailed = $debtors->where('vop_status', 'failed')->count();

        $bavSelected = $debtors->where('bav_selected', true)->count();
        $bavVerified = $debtors->where('bav_verified', true)->count();

        // Calculate percentages
        $validPct = $totalDebtors > 0 ? round(($validDebtors / $totalDebtors) * 100, 1) : 0;
        $invalidPct = $totalDebtors > 0 ? round(($invalidDebtors / $totalDebtors) * 100, 1) : 0;
        $vopSuccessPct = $vopProcessed > 0 ? round(($vopVerified / $vopProcessed) * 100, 1) : 0;
        $vopFailPct = $vopProcessed > 0 ? round(($vopFailed / $vopProcessed) * 100, 1) : 0;
        $bavSuccessPct = $bavSelected > 0 ? round(($bavVerified / $bavSelected) * 100, 1) : 0;

        // Get VOP logs for score distribution
        $vopLogs = VopLog::where('upload_id', $uploadId)->get();
        $avgScore = $vopLogs->avg('vop_score') ?? 0;

        $verified = $vopLogs->where('vop_score', '>=', self::SCORE_VERIFIED)->count();
        $likely = $vopLogs->where('vop_score', '>=', self::SCORE_LIKELY)->where('vop_score', '<', self::SCORE_VERIFIED)->count();
        $inconclusive = $vopLogs->where('vop_score', '>=', self::SCORE_INCONCLUSIVE)->where('vop_score', '<', self::SCORE_LIKELY)->count();
        $mismatch = $vopLogs->where('vop_score', '>=', self::SCORE_MISMATCH)->where('vop_score', '<', self::SCORE_INCONCLUSIVE)->count();
        $rejected = $vopLogs->where('vop_score', '<', self::SCORE_MISMATCH)->count();

        $totalVopLogs = $vopLogs->count();

        $report = <<<EOT
# VOP GENERAL SUMMARY - UPLOAD {$uploadId}
# Generated: {$timestamp}
# Original File: {$upload->filename}

================================================================================
EXECUTIVE SUMMARY
================================================================================

Upload ID: {$upload->id}
File: {$upload->filename}
Upload Date: {$upload->created_at->format('Y-m-d H:i:s')}
Processing Status: Completed

================================================================================
VALIDATION RESULTS
================================================================================

Total Records Uploaded: {$totalDebtors}

Validation Breakdown:
  ✓ Valid Debtors:         {$validDebtors} ({$validPct}%)
  ✗ Invalid Debtors:       {$invalidDebtors} ({$invalidPct}%)
  ────────────────────────────────────────────
  TOTAL:                   {$totalDebtors} (100%)

Invalid Debtor Reasons:
  • Missing IBAN:          TBD
  • Invalid IBAN Format:   TBD
  • Missing Email:         TBD
  • Name Validation Issues: TBD

================================================================================
VOP VERIFICATION RESULTS
================================================================================

VOP Processing:
  • Total Processed:       {$vopProcessed} debtors
  • Successfully Verified: {$vopVerified} debtors ({$vopSuccessPct}%)
  • Failed:                {$vopFailed} debtors ({$vopFailPct}%)
  • Average Score:         " . round($avgScore, 1) . "/100

Score Distribution (by range):
  ✓ VERIFIED (80-100):         {$verified} (" . round(($verified / max($totalVopLogs, 1)) * 100, 1) . "%)  ← HIGH CONFIDENCE
  ≈ LIKELY_VERIFIED (60-79):    {$likely} (" . round(($likely / max($totalVopLogs, 1)) * 100, 1) . "%)  ← MEDIUM CONFIDENCE
  ? INCONCLUSIVE (40-59):       {$inconclusive} (" . round(($inconclusive / max($totalVopLogs, 1)) * 100, 1) . "%)  ← LOW CONFIDENCE
  ⚠ MISMATCH (20-39):           {$mismatch} (" . round(($mismatch / max($totalVopLogs, 1)) * 100, 1) . "%)  ← POOR MATCH
  ✗ REJECTED (0-19):            {$rejected} (" . round(($rejected / max($totalVopLogs, 1)) * 100, 1) . "%)  ← CRITICAL FAILURE
  ────────────────────────────────────────────────────────────────
  TOTAL:                        {$totalVopLogs} (100%)

Failure Analysis:
  • Failed Verifications:  {$vopFailed} ({$vopFailPct}%)
  • Rejected (Score < 20): {$rejected} (" . round(($rejected / max($totalVopLogs, 1)) * 100, 1) . "%)
  • Total High Risk:       " . ($vopFailed + $rejected) . " (" . round((($vopFailed + $rejected) / max($totalDebtors, 1)) * 100, 1) . "% of all debtors)

Success Rate: {$vopSuccessPct}%
Failure Rate: {$vopFailPct}%

================================================================================
BAV VERIFICATION RESULTS
================================================================================

BAV Status: " . (config('services.iban.bav_enabled') ? 'ENABLED' : 'DISABLED') . "
BAV Sampling Rate: " . config('services.iban.bav_sampling_percentage', 10) . "%

BAV Processing:
  • Total Selected for BAV: {$bavSelected} debtors
  • Successfully Verified:  {$bavVerified} debtors ({$bavSuccessPct}%)
  • Failed/Unavailable:     " . ($bavSelected - $bavVerified) . " debtors (" . round((($bavSelected - $bavVerified) / max($bavSelected, 1)) * 100, 1) . "%)

BAV Cost: €" . number_format($bavSelected * 0.10, 2) . " ({$bavSelected} × €0.10 per verification)

================================================================================
PROCESSING RECOMMENDATIONS
================================================================================

✓ Ready for Processing:       " . ($verified + $likely) . " debtors (scores ≥ 60)
⚠ Manual Review Required:      " . ($inconclusive + $mismatch) . " debtors (scores 20-59)
✗ Reject/Block:                " . ($rejected + $vopFailed) . " debtors (scores < 20 or failed)

Total Approved: " . round((($verified + $likely) / max($totalDebtors, 1)) * 100, 1) . "%
Total Requiring Review: " . round((($inconclusive + $mismatch) / max($totalDebtors, 1)) * 100, 1) . "%
Total Rejected: " . round((($rejected + $vopFailed) / max($totalDebtors, 1)) * 100, 1) . "%

================================================================================
KEY METRICS
================================================================================

Overall Success Rate: {$vopSuccessPct}%
Overall Failure Rate: {$vopFailPct}%

Quality Score: " . round((($verified / max($totalVopLogs, 1)) * 100), 1) . "% (High confidence verifications)

Processing Efficiency:
  • Valid Debtors Processed: " . round(($vopProcessed / max($validDebtors, 1)) * 100, 1) . "%
  • BAV Coverage:            " . round(($bavSelected / max($vopProcessed, 1)) * 100, 1) . "%

================================================================================
FAILURE DETAILS
================================================================================

Total Failures: " . ($vopFailed + $rejected) . "

Failure Breakdown:
  • VOP Verification Failed: {$vopFailed} (" . round(($vopFailed / max($totalDebtors, 1)) * 100, 1) . "% of total)
  • Score Rejected (< 20):   {$rejected} (" . round(($rejected / max($totalDebtors, 1)) * 100, 1) . "% of total)

Common Failure Reasons:
  1. Bank not found / Invalid bank code
  2. IBAN validation failed
  3. Country not supported
  4. No SEPA support
  5. BAV name mismatch
  6. API timeout/errors

Next Steps for Failed Debtors:
  1. Review invalid IBANs manually
  2. Verify debtor information accuracy
  3. Check bank account status with debtor
  4. Consider retry for API timeouts
  5. Flag for manual processing or exclusion

================================================================================
COST ANALYSIS
================================================================================

IBAN API Calls (Unlimited): FREE
BAV API Calls: €" . number_format($bavSelected * 0.10, 2) . " ({$bavSelected} calls)
────────────────────────────────────
Total VOP Cost: €" . number_format($bavSelected * 0.10, 2) . "

Cost per Debtor: €" . number_format(($bavSelected * 0.10) / max($totalDebtors, 1), 4) . "
Cost per Verified Debtor: €" . number_format(($bavSelected * 0.10) / max($vopVerified, 1), 4) . "

================================================================================
END OF GENERAL SUMMARY
================================================================================

For detailed BAV results, see: vop_bav_report_upload_{$uploadId}_*.txt
For complete VOP report, see: vop_report_upload_{$uploadId}_*.txt

Generated: {$timestamp}
Report ID: VOP-SUMMARY-{$uploadId}

EOT;

        return $this->saveSummaryReport($uploadId, $upload->filename, $report);
    }

    /**
     * Save BAV-specific report
     */
    private function saveBavReport(int $uploadId, string $originalFilename, string $content): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);

        $filename = "vop_bav_report_upload_{$uploadId}_{$baseFilename}_{$timestamp}.txt";
        $path = self::REPORTS_DIR . '/' . $filename;

        // Save to S3
        Storage::disk('s3')->put($path, $content);

        return $path;
    }

    /**
     * Save general summary report
     */
    private function saveSummaryReport(int $uploadId, string $originalFilename, string $content): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $baseFilename = pathinfo($originalFilename, PATHINFO_FILENAME);

        $filename = "vop_summary_upload_{$uploadId}_{$baseFilename}_{$timestamp}.txt";
        $path = self::REPORTS_DIR . '/' . $filename;

        // Save to S3
        Storage::disk('s3')->put($path, $content);

        return $path;
    }

    /**
     * Generate tabular CSV format report for easy data analysis
     */
    private function generateTabularReport(int $uploadId, Upload $upload, $debtors): string
    {
        $timestamp = now()->format('Y-m-d_His');
        $baseFilename = pathinfo($upload->filename, PATHINFO_FILENAME);

        $filename = "bav_report_upload_{$uploadId}_{$baseFilename}_{$timestamp}.csv";
        $path = self::REPORTS_DIR . '/' . $filename;

        // Build CSV content in memory
        $output = fopen('php://temp', 'w');

        // Write header row - BAV information only
        fputcsv($output, [
            'first_name',
            'last_name',
            'iban',
            'bav_result',
            'bav_score',
            'bav_message'
        ]);

        // Write data rows - only include records where BAV was applied
        foreach ($debtors as $debtor) {
            // Skip debtors without VOP processing
            if ($debtor->vop_status === null) {
                continue;
            }

            // Only include debtors where BAV was selected/applied
            if (!$debtor->bav_selected) {
                continue;
            }

            $vopLog = $debtor->vopLogs()->latest()->first();

            // Get debtor name from database fields
            $firstName = $debtor->first_name ?? '';
            $lastName = $debtor->last_name ?? '';

            // Get BAV data only
            $bavResult = $vopLog ? ($vopLog->name_match ?? 'no') : 'no';
            $bavScore = $vopLog ? ($vopLog->name_match_score ?? '') : '';

            // Generate BAV message
            $bavMessage = $this->generateBavMessage($vopLog, $debtor, $bavResult);

            fputcsv($output, [
                $firstName,
                $lastName,
                $debtor->iban ?? '',
                $bavResult,
                $bavScore,
                $bavMessage
            ]);
        }

        // Get CSV content
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        // Save to S3 with error handling
        try {
            $result = Storage::disk('s3')->put($path, $csvContent);

            if (!$result) {
                Log::channel('bav')->error('Failed to save BAV CSV to S3', [
                    'upload_id' => $uploadId,
                    'path' => $path,
                    'content_length' => strlen($csvContent)
                ]);
                throw new \Exception("Failed to save BAV CSV report to S3: {$path}");
            }

            Log::channel('bav')->info('BAV CSV saved to S3 successfully', [
                'upload_id' => $uploadId,
                'path' => $path,
                'size' => strlen($csvContent)
            ]);
        } catch (\Exception $e) {
            Log::channel('bav')->error('Exception saving BAV CSV to S3', [
                'upload_id' => $uploadId,
                'path' => $path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        return $path;
    }

    /**
     * Get VOP status label based on score
     */
    private function getVopStatusLabel(int $score): string
    {
        if ($score >= self::SCORE_VERIFIED) {
            return 'verified';
        } elseif ($score >= self::SCORE_LIKELY) {
            return 'likely_verified';
        } elseif ($score >= self::SCORE_INCONCLUSIVE) {
            return 'inconclusive';
        } elseif ($score >= self::SCORE_MISMATCH) {
            return 'mismatch';
        } else {
            return 'invalid';
        }
    }

    /**
     * Generate VOP-specific message based on VOP score (without BAV considerations)
     */
    private function generateVopMessage($vopLog, int $vopScore): string
    {
        if (!$vopLog) {
            return 'Verification not completed';
        }

        // VOP success messages based on score ranges (without BAV)
        if ($vopScore >= self::SCORE_VERIFIED) {
            // 80-100: VERIFIED
            return 'Account verified. Bank and IBAN validated successfully.';
        } elseif ($vopScore >= self::SCORE_LIKELY) {
            // 60-79: LIKELY VERIFIED
            return 'Account likely valid. Consider for processing with manual review.';
        } elseif ($vopScore >= self::SCORE_INCONCLUSIVE) {
            // 40-59: INCONCLUSIVE
            return 'Verification inconclusive. Manual review required before processing.';
        } elseif ($vopScore >= self::SCORE_MISMATCH) {
            // 20-39: MISMATCH
            return 'Low verification score. High risk account.';
        } else {
            // 0-19: REJECTED
            if (!$vopLog->iban_valid) {
                return 'Invalid IBAN checksum. Account cannot be verified.';
            }
            if (!$vopLog->bank_identified) {
                return 'Bank not identified. Cannot verify account.';
            }
            return 'Verification failed. Do not process this account.';
        }
    }

    /**
     * Generate BAV-specific message based on BAV result and errors
     */
    private function generateBavMessage($vopLog, $debtor, string $bavResult): string
    {
        // If BAV was not selected/performed, return empty message
        if (!$debtor->bav_selected) {
            return '';
        }

        if (!$vopLog) {
            return 'BAV verification not performed';
        }

        // Check for BAV errors first (from meta data)
        $meta = is_array($vopLog->meta) ? $vopLog->meta : json_decode($vopLog->meta ?? '{}', true);
        $bavError = $meta['bav_result']['error'] ?? null;

        if ($bavError) {
            // Map BAV API errors to user-friendly messages
            return $this->getBavErrorMessage($bavError);
        }

        // BAV success messages based on name match result
        if ($bavResult === 'yes') {
            $nameMatchScore = $vopLog->name_match_score ?? 0;
            return "Name matches account owner (score: {$nameMatchScore}/100).";
        } elseif ($bavResult === 'partial') {
            $nameMatchScore = $vopLog->name_match_score ?? 0;
            return "Name partially matches account owner (score: {$nameMatchScore}/100).";
        } elseif ($bavResult === 'no') {
            // Check if BAV was skipped due to low VOP score
            $vopBaseScore = ($vopLog->vop_score ?? 0) - ($vopLog->score_name_match ?? 0);
            if ($vopBaseScore < 60) {
                return 'BAV not performed - VOP base score too low (< 60).';
            }
            return 'Name does not match account owner records.';
        } else {
            return 'BAV verification unavailable or failed.';
        }
    }

    /**
     * Get user-friendly BAV error message based on API error
     */
    private function getBavErrorMessage(string $error): string
    {
        $errorMap = [
            'INVALID_IBAN_CHECKSUM' => 'Invalid IBAN checksum. Cannot verify account owner.',
            'COUNTRY_NOT_SUPPORTED' => 'Bank account verification not supported for this country.',
            'VERIFICATION_NOT_SUPPORTED' => 'This bank does not support account verification.',
            'VOP_REQUEST_FAILED' => 'Bank verification service temporarily unavailable.',
            'NAME_NOT_MATCH' => 'Account owner name does not match bank records.',
            'NAME_PARTIAL_MATCH' => 'Account owner name partially matches bank records.',
            'INTERNAL_SERVER_ERROR' => 'Verification service error. Please retry.',
            'MISSING_INPUT_NAME' => 'Account owner name required for verification.',
            'MISSING_INPUT_IBAN' => 'IBAN required for verification.',
            'SUBSCRIPTION_EXPIRED' => 'Verification service subscription expired.',
            'NO_CREDITS_AVAILABLE' => 'Verification service credits exhausted.',
        ];

        return $errorMap[$error] ?? "Verification error: {$error}";
    }
}
