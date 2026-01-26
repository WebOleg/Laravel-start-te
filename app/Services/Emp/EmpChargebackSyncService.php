<?php

/**
 * Service for syncing chargebacks from EMP via /chargebacks/by_date API.
 * Backup mechanism for missed webhooks - runs daily to catch any missed chargeback events.
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
                $result = $this->processChargeback($chargeback, $dryRun, $stats);
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

    private function processChargeback(array $chargeback, bool $dryRun, array &$stats): string
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
                'post_date' => $chargeback['post_date'] ?? null,
            ]);
            return 'unmatched';
        }

        if ($billingAttempt->status === BillingAttempt::STATUS_CHARGEBACKED) {
            $this->ensureChargebackRecord($billingAttempt, $chargeback, $stats);
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

        return $this->applyChargeback($billingAttempt, $chargeback, $stats);
    }

    private function ensureChargebackRecord(BillingAttempt $billingAttempt, array $chargeback, array &$stats): void
    {
        $existing = $billingAttempt->chargeback;

        if (!$existing) {
            $created = $this->chargebackService->createFromApiSync($billingAttempt, $chargeback);
            if ($created) {
                $stats['chargebacks_created']++;
            }
        }
    }

    private function applyChargeback(BillingAttempt $billingAttempt, array $chargeback, array &$stats): string
    {
        try {
            DB::transaction(function () use ($billingAttempt, $chargeback, &$stats) {
                $reasonCode = $chargeback['reason_code'] ?? null;
                $reasonDescription = $chargeback['reason_description'] ?? null;
                $postDate = $chargeback['post_date'] ?? null;

                $billingAttempt->update([
                    'status' => BillingAttempt::STATUS_CHARGEBACKED,
                    'chargeback_reason_code' => $reasonCode,
                    'chargeback_reason_description' => $reasonDescription,
                    'chargebacked_at' => $postDate ? Carbon::parse($postDate) : now(),
                    'meta' => array_merge($billingAttempt->meta ?? [], [
                        'chargeback_sync' => [
                            'synced_at' => now()->toIso8601String(),
                            'source' => 'api_sync',
                            'type' => $chargeback['type'] ?? null,
                            'chargeback_amount' => $chargeback['chargeback_amount'] ?? null,
                            'chargeback_currency' => $chargeback['chargeback_currency'] ?? null,
                        ],
                    ]),
                ]);

                $created = $this->chargebackService->createFromApiSync($billingAttempt, $chargeback);
                if ($created) {
                    $stats['chargebacks_created']++;
                }

                $debtor = $billingAttempt->debtor;
                if ($debtor && $debtor->status !== Debtor::STATUS_CHARGEBACKED) {
                    $debtor->update(['status' => Debtor::STATUS_CHARGEBACKED]);
                }

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
