<?php

/**
 * Service for syncing SDD chargebacks from EMP via /chargebacks/by_date API.
 * Backup mechanism for missed webhooks - runs daily to catch any missed chargeback events.
 *
 * Note: For SDD, the API does NOT return post_date (bank registration date).
 * We use import_date as the chargeback discovery date instead.
 */

namespace App\Services\Emp;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Services\BlacklistService;
use App\Services\ChargebackService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmpChargebackSyncService
{
    private EmpClient $client;
    private BlacklistService $blacklistService;
    private ChargebackService $chargebackService;

    public const PER_PAGE = 100;
    public const RATE_LIMIT_DELAY_MS = 500;

    public const AUTO_BLACKLIST_CODES = ['AC04', 'AC06', 'AG01', 'MD01'];

    public function __construct(
        EmpClient $client,
        BlacklistService $blacklistService,
        ChargebackService $chargebackService
    ) {
        $this->client = $client;
        $this->blacklistService = $blacklistService;
        $this->chargebackService = $chargebackService;
    }

    public function syncByDate(string $date, bool $dryRun = false): array
    {
        $stats = [
            'date' => $date,
            'dry_run' => $dryRun,
            'total_fetched' => 0,
            'matched' => 0,
            'already_processed' => 0,
            'unmatched' => 0,
            'errors' => 0,
            'blacklisted' => 0,
            'pages_processed' => 0,
            'chargebacks_created' => 0,
            'reason_backfilled' => 0,
            'profiles_deactivated' => 0,
        ];

        Log::info('EMP Chargeback Sync: Starting', ['date' => $date, 'dry_run' => $dryRun]);

        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $response = $this->client->getChargebacksByImportDate($date, $page, self::PER_PAGE);

            if (isset($response['status']) && $response['status'] === 'error') {
                Log::error('EMP Chargeback Sync: API error', [
                    'date' => $date,
                    'page' => $page,
                    'error' => $response['technical_message'] ?? 'Unknown error',
                ]);
                $stats['errors']++;
                break;
            }

            $chargebacks = $this->extractChargebacks($response);
            $pagesCount = (int) ($response['@pages_count'] ?? 1);

            $stats['total_fetched'] += count($chargebacks);
            $stats['pages_processed']++;

            foreach ($chargebacks as $chargeback) {
                $result = $this->processChargeback($chargeback, $dryRun, $stats, $date);
                $stats[$result]++;
            }

            Log::info('EMP Chargeback Sync: Page processed', [
                'date' => $date,
                'page' => $page,
                'pages_count' => $pagesCount,
                'chargebacks_on_page' => count($chargebacks),
            ]);

            $hasMore = $page < $pagesCount;
            $page++;

            if ($hasMore) {
                usleep(self::RATE_LIMIT_DELAY_MS * 1000);
            }
        }

        Log::info('EMP Chargeback Sync: Completed', $stats);

        return $stats;
    }

    public function syncByDateRange(string $startDate, string $endDate, bool $dryRun = false): array
    {
        $results = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            $dateStr = $current->format('Y-m-d');
            $results[$dateStr] = $this->syncByDate($dateStr, $dryRun);
            $current->addDay();

            if ($current->lte($end)) {
                usleep(self::RATE_LIMIT_DELAY_MS * 1000);
            }
        }

        return $results;
    }

    private function extractChargebacks(array $response): array
    {
        if (!isset($response['chargeback_response'])) {
            return [];
        }

        $chargebacks = $response['chargeback_response'];

        if (isset($chargebacks['original_transaction_unique_id'])) {
            return [$chargebacks];
        }

        return $chargebacks;
    }

    private function processChargeback(array $chargeback, bool $dryRun, array &$stats, string $importDate): string
    {
        $originalUniqueId = $chargeback['original_transaction_unique_id'] ?? null;

        if (!$originalUniqueId) {
            Log::warning('EMP Chargeback Sync: Missing original_transaction_unique_id', [
                'chargeback' => $chargeback,
            ]);
            return 'errors';
        }

        $billingAttempt = BillingAttempt::where('unique_id', $originalUniqueId)->first();

        if (!$billingAttempt) {
            Log::warning('EMP Chargeback Sync: Unmatched chargeback', [
                'original_unique_id' => $originalUniqueId,
                'reason_code' => $chargeback['reason_code'] ?? null,
            ]);
            return 'unmatched';
        }

        if ($billingAttempt->status === BillingAttempt::STATUS_CHARGEBACKED) {
            $this->ensureChargebackRecord($billingAttempt, $chargeback, $stats, $importDate);
            return 'already_processed';
        }

        if ($dryRun) {
            Log::info('EMP Chargeback Sync: Would process (dry-run)', [
                'billing_attempt_id' => $billingAttempt->id,
                'unique_id' => $originalUniqueId,
                'reason_code' => $chargeback['reason_code'] ?? null,
            ]);
            return 'matched';
        }

        return $this->applyChargeback($billingAttempt, $chargeback, $stats, $importDate);
    }

    /**
     * Ensure chargeback record exists for already-chargebacked billing attempts.
     * Also backfill reason_code on billing_attempt if missing and ensure
     * debtor profile is deactivated — it may have been missed if chargeback
     * was previously processed without profile deactivation.
     */
    private function ensureChargebackRecord(BillingAttempt $billingAttempt, array $chargeback, array &$stats, string $importDate): void
    {
        $reasonCode = $chargeback['reason_code'] ?? null;
        $reasonDescription = $chargeback['reason_description'] ?? null;

        if ($reasonCode && !$billingAttempt->chargeback_reason_code) {
            $billingAttempt->update([
                'chargeback_reason_code' => $reasonCode,
                'chargeback_reason_description' => $reasonDescription,
            ]);

            $stats['reason_backfilled'] = ($stats['reason_backfilled'] ?? 0) + 1;
            Log::info('EMP Chargeback Sync: Backfilled reason code', [
                'billing_attempt_id' => $billingAttempt->id,
                'reason_code' => $reasonCode,
            ]);
        }

        $existing = $billingAttempt->chargeback;
        if (!$existing) {
            $chargeback['import_date'] = $importDate;
            $created = $this->chargebackService->createFromApiSync($billingAttempt, $chargeback);
            if ($created) {
                $stats['chargebacks_created']++;
            }
        }

        // Ensure profile is deactivated — may have been missed on previous sync runs.
        $this->deactivateProfile($billingAttempt, $chargeback['chargeback_amount'] ?? null, $stats);
    }

    private function applyChargeback(BillingAttempt $billingAttempt, array $chargeback, array &$stats, string $importDate): string
    {
        try {
            DB::transaction(function () use ($billingAttempt, $chargeback, &$stats, $importDate) {
                $reasonCode = $chargeback['reason_code'] ?? null;
                $reasonDescription = $chargeback['reason_description'] ?? null;

                $billingAttempt->update([
                    'status' => BillingAttempt::STATUS_CHARGEBACKED,
                    'chargeback_reason_code' => $reasonCode,
                    'chargeback_reason_description' => $reasonDescription,
                    'chargebacked_at' => Carbon::parse($importDate),
                    'meta' => array_merge($billingAttempt->meta ?? [], [
                        'chargeback_sync' => [
                            'synced_at' => now()->toIso8601String(),
                            'source' => 'api_sync',
                            'import_date' => $importDate,
                            'type' => $chargeback['type'] ?? null,
                            'chargeback_amount' => $chargeback['chargeback_amount'] ?? null,
                        ],
                    ]),
                ]);

                $chargeback['import_date'] = $importDate;
                $created = $this->chargebackService->createFromApiSync($billingAttempt, $chargeback);
                if ($created) {
                    $stats['chargebacks_created']++;
                }

                $debtor = $billingAttempt->debtor;
                if ($debtor && $debtor->status !== Debtor::STATUS_CHARGEBACKED) {
                    $debtor->update(['status' => Debtor::STATUS_CHARGEBACKED]);
                }

                // Deactivate debtor profile — mirrors webhook path (ProcessEmpWebhookJob::deactivateProfile).
                // Critical: sync path must produce identical profile state as webhook path.
                $this->deactivateProfile($billingAttempt, $chargeback['chargeback_amount'] ?? null, $stats);

                if ($reasonCode && in_array($reasonCode, self::AUTO_BLACKLIST_CODES) && $debtor) {
                    $this->blacklistDebtor($debtor, $reasonCode, $reasonDescription, $stats);
                }
            });

            Log::info('EMP Chargeback Sync: Applied chargeback', [
                'billing_attempt_id' => $billingAttempt->id,
                'unique_id' => $billingAttempt->unique_id,
                'reason_code' => $chargeback['reason_code'] ?? null,
            ]);

            return 'matched';

        } catch (\Exception $e) {
            Log::error('EMP Chargeback Sync: Failed to apply chargeback', [
                'billing_attempt_id' => $billingAttempt->id,
                'error' => $e->getMessage(),
            ]);
            return 'errors';
        }
    }

    /**
     * Deactivate debtor profile after chargeback.
     * Mirrors ProcessEmpWebhookJob::deactivateProfile — both paths must produce identical state.
     * Idempotent: safe to call multiple times on the same profile.
     * Note: lifetime amount is updated inline without calling deductLifetimeRevenue()
     * to avoid nested save() calls inside DB::transaction.
     */
    private function deactivateProfile(BillingAttempt $billingAttempt, mixed $chargebackAmount, array &$stats): void
    {
        $profile = $billingAttempt->debtorProfile ?? $billingAttempt->debtor?->debtorProfile;

        if (!$profile) {
            return;
        }

        if (!$profile->is_active) {
            return;
        }

        $amountToDeduct = $chargebackAmount ?? $billingAttempt->amount;
        if ($amountToDeduct > 0) {
            $current = $profile->lifetime_charged_amount ?? 0;
            $profile->lifetime_charged_amount = max(0, $current - (float) $amountToDeduct);
        }

        $profile->is_active = false;
        $profile->next_bill_at = null;
        $profile->save();

        $stats['profiles_deactivated'] = ($stats['profiles_deactivated'] ?? 0) + 1;

        Log::info('EMP Chargeback Sync: Profile deactivated', [
            'billing_attempt_id' => $billingAttempt->id,
            'debtor_profile_id' => $profile->id,
            'amount_deducted' => $amountToDeduct,
        ]);
    }

    private function blacklistDebtor(Debtor $debtor, string $reasonCode, ?string $reasonDescription, array &$stats): void
    {
        try {
            $reason = "Chargeback: {$reasonCode}" . ($reasonDescription ? " - {$reasonDescription}" : '');
            $this->blacklistService->addDebtor($debtor, $reason, 'chargeback_sync');
            $stats['blacklisted']++;

            Log::info('EMP Chargeback Sync: Debtor blacklisted', [
                'debtor_id' => $debtor->id,
                'reason_code' => $reasonCode,
            ]);

        } catch (\Exception $e) {
            Log::warning('EMP Chargeback Sync: Failed to blacklist debtor', [
                'debtor_id' => $debtor->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
