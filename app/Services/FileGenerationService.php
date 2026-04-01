<?php

/**
 * Service for "File Generation" — record selection + amount assignment pipeline.
 *
 * Pricing strategies:
 *   - uniform_random:  Random pick from all fitting amounts (equal distribution)
 *   - high_bias:       Biased toward higher amounts, fully random near target
 *   - low_volume:      Small amounts (9.99–29.99) for high-volume, low-value files
 *   - mid_range:       Mid-range amounts (29.99–69.99) balanced distribution
 *   - premium:         High amounts only (69.99–99.99) for fewer, bigger records
 *   - bell_curve:      Bell-curve distribution centered on mid-range amounts
 *   - custom:          User-provided price ladder
 */

namespace App\Services;

use App\Models\BillingAttempt;
use App\Models\FileGenerationBatch;
use App\Models\FileGenerationRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileGenerationService
{
    /**
     * Full price ladder: 9.99, 19.99, 29.99, ..., 99.99
     */
    public const PRICE_LADDER = [
        9.99, 19.99, 29.99, 39.99, 49.99,
        59.99, 69.99, 79.99, 89.99, 99.99,
    ];

    /**
     * Available pricing strategy presets.
     * Each defines a subset of amounts and/or weighting behaviour.
     */
    public const PRICING_STRATEGIES = [
        'uniform_random' => [
            'label'       => 'Uniform Random',
            'description' => 'Equal chance for any amount from the full ladder',
            'amounts'     => [9.99, 19.99, 29.99, 39.99, 49.99, 59.99, 69.99, 79.99, 89.99, 99.99],
            'weights'     => [1, 1, 1, 1, 1, 1, 1, 1, 1, 1],
        ],
        'high_bias' => [
            'label'       => 'High Bias',
            'description' => 'Favours higher amounts, more random near target',
            'amounts'     => [9.99, 19.99, 29.99, 39.99, 49.99, 59.99, 69.99, 79.99, 89.99, 99.99],
            'weights'     => [1, 1, 2, 2, 3, 3, 4, 5, 6, 8],
        ],
        'low_volume' => [
            'label'       => 'Low Volume (€9.99–€29.99)',
            'description' => 'Small amounts for high-volume files — more records, lower per-record value',
            'amounts'     => [9.99, 19.99, 29.99],
            'weights'     => [3, 2, 1],
        ],
        'mid_range' => [
            'label'       => 'Mid Range (€29.99–€69.99)',
            'description' => 'Balanced mid-range amounts',
            'amounts'     => [29.99, 39.99, 49.99, 59.99, 69.99],
            'weights'     => [1, 2, 3, 2, 1],
        ],
        'premium' => [
            'label'       => 'Premium (€69.99–€99.99)',
            'description' => 'Fewer records with high-value amounts',
            'amounts'     => [69.99, 79.99, 89.99, 99.99],
            'weights'     => [1, 2, 3, 4],
        ],
        'bell_curve' => [
            'label'       => 'Bell Curve',
            'description' => 'Normal distribution — peaks at €49.99–€59.99, tapers at extremes',
            'amounts'     => [9.99, 19.99, 29.99, 39.99, 49.99, 59.99, 69.99, 79.99, 89.99, 99.99],
            'weights'     => [1, 2, 4, 7, 10, 10, 7, 4, 2, 1],
        ],
    ];

    public const DEFAULT_STRATEGY = 'high_bias';
    public const BILLING_INACTIVITY_DAYS = 30;

    public function __construct(
        private BlacklistService $blacklistService,
        private IbanValidator    $ibanValidator,
    ) {}

    /**
     * Return the list of available strategies for the frontend.
     */
    public static function getAvailableStrategies(): array
    {
        $strategies = [];
        foreach (self::PRICING_STRATEGIES as $key => $config) {
            $strategies[] = [
                'key'         => $key,
                'label'       => $config['label'],
                'description' => $config['description'],
                'amounts'     => $config['amounts'],
            ];
        }
        return $strategies;
    }

    /**
     * Validate a strategy key or custom amounts array.
     *
     * @throws \InvalidArgumentException
     */
    public static function validateStrategy(string $strategy, ?array $customAmounts = null): array
    {
        if ($strategy === 'custom') {
            if (empty($customAmounts)) {
                throw new \InvalidArgumentException('Custom strategy requires at least one amount.');
            }
            foreach ($customAmounts as $amount) {
                if (!is_numeric($amount) || $amount <= 0 || $amount > 999.99) {
                    throw new \InvalidArgumentException("Invalid custom amount: {$amount}. Must be between 0.01 and 999.99.");
                }
            }
            $amounts = array_map(fn ($a) => round((float) $a, 2), $customAmounts);
            sort($amounts);
            $weights = array_fill(0, count($amounts), 1); // equal weight for custom
            return ['amounts' => $amounts, 'weights' => $weights];
        }

        if (!isset(self::PRICING_STRATEGIES[$strategy])) {
            throw new \InvalidArgumentException(
                "Unknown pricing strategy: {$strategy}. Available: " . implode(', ', array_keys(self::PRICING_STRATEGIES))
            );
        }

        $config = self::PRICING_STRATEGIES[$strategy];
        return ['amounts' => $config['amounts'], 'weights' => $config['weights']];
    }

    // ═══════════════════════════════════════════════════════════════════
    // FILTERING
    // ═══════════════════════════════════════════════════════════════════

    public function loadPreviouslyUsedIbans(): array
    {
        $used = [];

        $batchIds = FileGenerationBatch::where('status', 'completed')->pluck('id');

        if ($batchIds->isEmpty()) {
            return $used;
        }

        $query = FileGenerationRecord::query()
            ->whereIn('batch_id', $batchIds)
            ->select('iban')
            ->distinct();

        foreach ($query->cursor() as $record) {
            $used[$record->iban] = true;
        }

        return $used;
    }

    public function loadRecentlyBilledIbans(int $days = self::BILLING_INACTIVITY_DAYS): array
    {
        $since = now()->subDays($days);
        $billed = [];

        $ibanQuery = DB::table('billing_attempts')
            ->join('debtors', 'billing_attempts.debtor_id', '=', 'debtors.id')
            ->where('billing_attempts.created_at', '>=', $since)
            ->whereNotIn('billing_attempts.status', [BillingAttempt::STATUS_VOIDED])
            ->select('debtors.iban')
            ->distinct();

        foreach ($ibanQuery->cursor() as $row) {
            $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $row->iban ?? ''));
            if ($normalised !== '') {
                $billed[$normalised] = true;
            }
        }

        return $billed;
    }

    public function checkEligibility(
        string $iban,
        array  $previouslyUsed,
        array  $recentlyBilled,
    ): array {
        $validation = $this->ibanValidator->validate($iban);
        if (!$validation['valid']) {
            return ['eligible' => false, 'reason' => 'Invalid IBAN'];
        }
        if (!$validation['is_sepa']) {
            return ['eligible' => false, 'reason' => 'Non-SEPA country'];
        }

        if ($this->blacklistService->isBlacklisted($iban)) {
            return ['eligible' => false, 'reason' => 'IBAN blacklisted'];
        }

        if (isset($previouslyUsed[$iban])) {
            return ['eligible' => false, 'reason' => 'Previously used'];
        }

        if (isset($recentlyBilled[$iban])) {
            return ['eligible' => false, 'reason' => 'Billing activity in last 30 days'];
        }

        return ['eligible' => true, 'reason' => null];
    }

    // ═══════════════════════════════════════════════════════════════════
    // AMOUNT ASSIGNMENT + RECORD SELECTION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Select records and assign amounts using the specified pricing strategy.
     *
     * When an exact match within tolerance is not possible the algorithm
     * always prefers to *exceed* the target rather than fall short.
     *
     * @param  array   $eligibleRows    Array of ['row_index' => int, 'row' => array, 'iban' => string]
     * @param  float   $targetAmount    e.g. 13000.00
     * @param  float   $tolerance       e.g. 200.00
     * @param  array   $amounts         Price ladder to use
     * @param  array   $weights         Corresponding weights for weighted random selection
     * @return array{selected: array[], achieved_amount: float}
     */
    public function selectAndAssignAmounts(
        array $eligibleRows,
        float $targetAmount,
        float $tolerance,
        array $amounts,
        array $weights,
    ): array {
        $selected     = [];
        $runningTotal = 0.0;
        $upperBound   = $targetAmount + $tolerance;

        shuffle($eligibleRows);

        foreach ($eligibleRows as $candidate) {
            // Already at or above the target — stop.
            if ($runningTotal >= $targetAmount) {
                break;
            }

            $remaining = $upperBound - $runningTotal;

            // Filter amounts that fit within the upper bound
            $fitting        = [];
            $fittingWeights = [];
            foreach ($amounts as $i => $amount) {
                if ($amount <= $remaining) {
                    $fitting[]        = $amount;
                    $fittingWeights[] = $weights[$i] ?? 1;
                }
            }

            if (!empty($fitting)) {
                $chosenAmount = $this->weightedRandom($fitting, $fittingWeights);
            } else {
                // No amount fits within the upper bound, but we're still
                // below the target — force the smallest amount to push us
                // over the target rather than leaving a shortfall.
                $chosenAmount = min($amounts);
            }

            $selected[] = [
                'row_index'       => $candidate['row_index'],
                'row'             => $candidate['row'],
                'iban'            => $candidate['iban'],
                'assigned_amount' => $chosenAmount,
            ];

            $runningTotal += $chosenAmount;
        }

        return [
            'selected'        => $selected,
            'achieved_amount' => round($runningTotal, 2),
        ];
    }

    /**
     * Weighted random selection from parallel arrays.
     */
    private function weightedRandom(array $items, array $weights): mixed
    {
        $totalWeight = array_sum($weights);
        $rand        = mt_rand(1, (int) ($totalWeight * 100)) / 100;
        $cumulative  = 0.0;

        foreach ($items as $i => $item) {
            $cumulative += $weights[$i];
            if ($rand <= $cumulative) {
                return $item;
            }
        }

        return end($items);
    }

    // ═══════════════════════════════════════════════════════════════════
    // PERSISTENCE
    // ═══════════════════════════════════════════════════════════════════

    public function persistSelectedRecords(FileGenerationBatch $batch, array $selected): void
    {
        $records = [];
        $seen = [];
        $now = now();

        foreach ($selected as $item) {
            if (isset($seen[$item['iban']])) {
                continue;
            }
            $seen[$item['iban']] = true;

            $records[] = [
                'batch_id'         => $batch->id,
                'iban'             => $item['iban'],
                'amount'           => $item['assigned_amount'],
                'source_row_index' => $item['row_index'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        foreach (array_chunk($records, 1000) as $chunk) {
            FileGenerationRecord::insert($chunk);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // CSV OUTPUT
    // ═══════════════════════════════════════════════════════════════════

    public function buildOutputHeaders(array $sourceHeaders, bool $bicInjected): array
    {
        $headers = $sourceHeaders;
        if ($bicInjected) {
            $headers[] = 'bic';
        }
        if (!in_array('amount', $headers)) {
            $headers[] = 'amount';
        }
        return $headers;
    }

    public function writeOutputRow(
        $handle,
        array  $outputHeaders,
        array  $row,
        float  $assignedAmount,
        bool   $bicInjected,
        string $resolvedBic = '',
    ): void {
        $line = [];
        foreach ($outputHeaders as $h) {
            if ($h === 'amount') {
                $line[] = number_format($assignedAmount, 2, '.', '');
            } elseif ($bicInjected && $h === 'bic') {
                $line[] = $resolvedBic;
            } else {
                $line[] = $row[$h] ?? '';
            }
        }
        fputcsv($handle, $line);
    }
}
