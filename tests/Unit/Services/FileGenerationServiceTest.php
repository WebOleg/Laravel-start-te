<?php

/**
 * Unit tests for FileGenerationService.
 *
 * Covers: pricing strategies, validation, eligibility checks,
 * amount selection/assignment, persistence, and CSV output helpers.
 */

namespace Tests\Unit\Services;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\FileGenerationBatch;
use App\Models\FileGenerationRecord;
use App\Models\Upload;
use App\Models\User;
use App\Services\BlacklistService;
use App\Services\FileGenerationService;
use App\Services\IbanValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class FileGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileGenerationService $service;
    private MockInterface $blacklistMock;
    private MockInterface $ibanValidatorMock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->blacklistMock = Mockery::mock(BlacklistService::class);
        $this->ibanValidatorMock = Mockery::mock(IbanValidator::class);

        $this->service = new FileGenerationService(
            $this->blacklistMock,
            $this->ibanValidatorMock,
        );
    }

    private function createBatch(array $overrides = []): FileGenerationBatch
    {
        return FileGenerationBatch::create(array_merge([
            'token' => Str::uuid()->toString(),
            'admin_id' => $this->user->id,
            'source_file' => 'test.csv',
            's3_path_source' => 'uploads/test.csv',
            'status' => 'completed',
            'target_amount' => 5000,
            'tolerance' => 500,
            'pricing_strategy' => 'high_bias',
            'total_input_rows' => 200,
        ], $overrides));
    }

    // ══════════════════════════════════════════════
    // getAvailableStrategies()
    // ══════════════════════════════════════════════

    public function test_get_available_strategies_returns_all_strategies(): void
    {
        $strategies = FileGenerationService::getAvailableStrategies();

        $this->assertIsArray($strategies);
        $this->assertCount(count(FileGenerationService::PRICING_STRATEGIES), $strategies);
    }

    public function test_get_available_strategies_each_has_required_keys(): void
    {
        $strategies = FileGenerationService::getAvailableStrategies();

        foreach ($strategies as $strategy) {
            $this->assertArrayHasKey('key', $strategy);
            $this->assertArrayHasKey('label', $strategy);
            $this->assertArrayHasKey('description', $strategy);
            $this->assertArrayHasKey('amounts', $strategy);
        }
    }

    public function test_get_available_strategies_keys_match_constants(): void
    {
        $strategies = FileGenerationService::getAvailableStrategies();
        $keys = array_column($strategies, 'key');

        foreach (array_keys(FileGenerationService::PRICING_STRATEGIES) as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }

    public function test_get_available_strategies_does_not_include_custom(): void
    {
        $strategies = FileGenerationService::getAvailableStrategies();
        $keys = array_column($strategies, 'key');

        $this->assertNotContains('custom', $keys);
    }

    // ══════════════════════════════════════════════
    // validateStrategy()
    // ══════════════════════════════════════════════

    public function test_validate_strategy_returns_amounts_and_weights_for_known_strategy(): void
    {
        $result = FileGenerationService::validateStrategy('uniform_random');

        $this->assertArrayHasKey('amounts', $result);
        $this->assertArrayHasKey('weights', $result);
        $this->assertCount(10, $result['amounts']);
        $this->assertCount(10, $result['weights']);
    }

    public function test_validate_strategy_returns_correct_amounts_for_low_volume(): void
    {
        $result = FileGenerationService::validateStrategy('low_volume');

        $this->assertEquals([9.99, 19.99, 29.99], $result['amounts']);
        $this->assertEquals([3, 2, 1], $result['weights']);
    }

    public function test_validate_strategy_returns_correct_amounts_for_premium(): void
    {
        $result = FileGenerationService::validateStrategy('premium');

        $this->assertEquals([69.99, 79.99, 89.99, 99.99], $result['amounts']);
        $this->assertEquals([1, 2, 3, 4], $result['weights']);
    }

    public function test_validate_strategy_returns_correct_amounts_for_mid_range(): void
    {
        $result = FileGenerationService::validateStrategy('mid_range');

        $this->assertEquals([29.99, 39.99, 49.99, 59.99, 69.99], $result['amounts']);
    }

    public function test_validate_strategy_returns_correct_amounts_for_bell_curve(): void
    {
        $result = FileGenerationService::validateStrategy('bell_curve');

        $this->assertCount(10, $result['amounts']);
        // Bell curve peaks at middle
        $this->assertEquals(10, $result['weights'][4]); // 49.99
        $this->assertEquals(10, $result['weights'][5]); // 59.99
        $this->assertEquals(1, $result['weights'][0]);  // 9.99
    }

    public function test_validate_strategy_returns_correct_for_high_bias(): void
    {
        $result = FileGenerationService::validateStrategy('high_bias');

        $this->assertCount(10, $result['amounts']);
        // High bias: last weight should be highest
        $this->assertGreaterThan($result['weights'][0], $result['weights'][9]);
    }

    public function test_validate_strategy_throws_for_unknown_strategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown pricing strategy: nonexistent');

        FileGenerationService::validateStrategy('nonexistent');
    }

    public function test_validate_strategy_works_for_all_defined_strategies(): void
    {
        foreach (array_keys(FileGenerationService::PRICING_STRATEGIES) as $key) {
            $result = FileGenerationService::validateStrategy($key);

            $this->assertNotEmpty($result['amounts'], "Strategy '{$key}' has no amounts");
            $this->assertCount(
                count($result['amounts']),
                $result['weights'],
                "Strategy '{$key}' amounts/weights count mismatch"
            );
        }
    }

    // ──────────────────────────────────────────────
    // validateStrategy() — Custom Strategy
    // ──────────────────────────────────────────────

    public function test_validate_custom_strategy_with_valid_amounts(): void
    {
        $result = FileGenerationService::validateStrategy('custom', [19.99, 9.99, 49.99]);

        $this->assertEquals([9.99, 19.99, 49.99], $result['amounts']); // sorted
        $this->assertEquals([1, 1, 1], $result['weights']); // equal weights
    }

    public function test_validate_custom_strategy_throws_when_amounts_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom strategy requires at least one amount.');

        FileGenerationService::validateStrategy('custom', []);
    }

    public function test_validate_custom_strategy_throws_when_amounts_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom strategy requires at least one amount.');

        FileGenerationService::validateStrategy('custom', null);
    }

    public function test_validate_custom_strategy_throws_for_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid custom amount');

        FileGenerationService::validateStrategy('custom', [0]);
    }

    public function test_validate_custom_strategy_throws_for_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid custom amount');

        FileGenerationService::validateStrategy('custom', [-5.00]);
    }

    public function test_validate_custom_strategy_throws_for_amount_over_max(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid custom amount');

        FileGenerationService::validateStrategy('custom', [1000.00]);
    }

    public function test_validate_custom_strategy_rounds_amounts(): void
    {
        $result = FileGenerationService::validateStrategy('custom', [9.999, 19.991]);

        $this->assertEquals(10.0, $result['amounts'][0]);
        $this->assertEquals(19.99, $result['amounts'][1]);
    }

    public function test_validate_custom_strategy_sorts_amounts(): void
    {
        $result = FileGenerationService::validateStrategy('custom', [99.99, 9.99, 49.99]);

        $this->assertEquals([9.99, 49.99, 99.99], $result['amounts']);
    }

    public function test_validate_custom_strategy_accepts_single_amount(): void
    {
        $result = FileGenerationService::validateStrategy('custom', [25.00]);

        $this->assertEquals([25.0], $result['amounts']);
        $this->assertEquals([1], $result['weights']);
    }

    // ══════════════════════════════════════════════
    // checkEligibility()
    // ══════════════════════════════════════════════

    public function test_check_eligibility_returns_eligible_for_valid_iban(): void
    {
        $iban = 'DE89370400440532013000';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => true, 'is_sepa' => true]);

        $this->blacklistMock->shouldReceive('isBlacklisted')
            ->with($iban)
            ->once()
            ->andReturn(false);

        $result = $this->service->checkEligibility($iban, [], []);

        $this->assertTrue($result['eligible']);
        $this->assertNull($result['reason']);
    }

    public function test_check_eligibility_rejects_invalid_iban(): void
    {
        $iban = 'INVALID';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => false, 'is_sepa' => false]);

        $result = $this->service->checkEligibility($iban, [], []);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('Invalid IBAN', $result['reason']);
    }

    public function test_check_eligibility_rejects_non_sepa_iban(): void
    {
        $iban = 'US12345678901234';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => true, 'is_sepa' => false]);

        $result = $this->service->checkEligibility($iban, [], []);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('Non-SEPA country', $result['reason']);
    }

    public function test_check_eligibility_rejects_blacklisted_iban(): void
    {
        $iban = 'DE89370400440532013000';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => true, 'is_sepa' => true]);

        $this->blacklistMock->shouldReceive('isBlacklisted')
            ->with($iban)
            ->once()
            ->andReturn(true);

        $result = $this->service->checkEligibility($iban, [], []);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('IBAN blacklisted', $result['reason']);
    }

    public function test_check_eligibility_rejects_previously_used_iban(): void
    {
        $iban = 'DE89370400440532013000';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => true, 'is_sepa' => true]);

        $this->blacklistMock->shouldReceive('isBlacklisted')
            ->with($iban)
            ->once()
            ->andReturn(false);

        $previouslyUsed = [$iban => true];

        $result = $this->service->checkEligibility($iban, $previouslyUsed, []);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('Previously used', $result['reason']);
    }

    public function test_check_eligibility_rejects_recently_billed_iban(): void
    {
        $iban = 'DE89370400440532013000';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => true, 'is_sepa' => true]);

        $this->blacklistMock->shouldReceive('isBlacklisted')
            ->with($iban)
            ->once()
            ->andReturn(false);

        $recentlyBilled = [$iban => true];

        $result = $this->service->checkEligibility($iban, [], $recentlyBilled);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('Billing activity in last 30 days', $result['reason']);
    }

    public function test_check_eligibility_priority_invalid_before_blacklist(): void
    {
        $iban = 'INVALID';

        $this->ibanValidatorMock->shouldReceive('validate')
            ->with($iban)
            ->once()
            ->andReturn(['valid' => false, 'is_sepa' => false]);

        // Blacklist should NOT be called if IBAN is invalid
        $this->blacklistMock->shouldNotReceive('isBlacklisted');

        $result = $this->service->checkEligibility($iban, [$iban => true], [$iban => true]);

        $this->assertFalse($result['eligible']);
        $this->assertEquals('Invalid IBAN', $result['reason']);
    }

    // ══════════════════════════════════════════════
    // selectAndAssignAmounts()
    // ══════════════════════════════════════════════

    public function test_select_and_assign_returns_empty_when_no_eligible_rows(): void
    {
        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: [],
            targetAmount: 5000,
            tolerance: 500,
            amounts: [9.99, 19.99],
            weights: [1, 1],
        );

        $this->assertEmpty($result['selected']);
        $this->assertEquals(0, $result['achieved_amount']);
    }

    public function test_select_and_assign_selects_rows_up_to_target(): void
    {
        $eligibleRows = [];
        for ($i = 0; $i < 200; $i++) {
            $eligibleRows[] = [
                'row_index' => $i,
                'row' => ['first_name' => "User{$i}", 'iban' => "DE{$i}"],
                'iban' => "DE{$i}",
            ];
        }

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 500,
            tolerance: 50,
            amounts: [49.99],
            weights: [1],
        );

        $this->assertNotEmpty($result['selected']);
        $this->assertGreaterThanOrEqual(450, $result['achieved_amount']);
        $this->assertLessThanOrEqual(550, $result['achieved_amount']);
    }

    public function test_select_and_assign_respects_tolerance_bounds(): void
    {
        $eligibleRows = [];
        for ($i = 0; $i < 1000; $i++) {
            $eligibleRows[] = [
                'row_index' => $i,
                'row' => ['iban' => "DE{$i}"],
                'iban' => "DE{$i}",
            ];
        }

        $target = 1000;
        $tolerance = 100;

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: $target,
            tolerance: $tolerance,
            amounts: [9.99, 19.99, 29.99],
            weights: [1, 1, 1],
        );

        $this->assertGreaterThanOrEqual($target - $tolerance, $result['achieved_amount']);
        $this->assertLessThanOrEqual($target + $tolerance, $result['achieved_amount']);
    }

    public function test_select_and_assign_assigns_amount_to_each_selected_row(): void
    {
        $eligibleRows = [];
        for ($i = 0; $i < 50; $i++) {
            $eligibleRows[] = [
                'row_index' => $i,
                'row' => ['first_name' => "User{$i}"],
                'iban' => "DE{$i}",
            ];
        }

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 200,
            tolerance: 50,
            amounts: [9.99, 19.99],
            weights: [1, 1],
        );

        foreach ($result['selected'] as $record) {
            $this->assertArrayHasKey('assigned_amount', $record);
            $this->assertArrayHasKey('row_index', $record);
            $this->assertArrayHasKey('row', $record);
            $this->assertArrayHasKey('iban', $record);
            $this->assertContains($record['assigned_amount'], [9.99, 19.99]);
        }
    }

    public function test_select_and_assign_with_single_amount(): void
    {
        $eligibleRows = [];
        for ($i = 0; $i < 20; $i++) {
            $eligibleRows[] = [
                'row_index' => $i,
                'row' => [],
                'iban' => "DE{$i}",
            ];
        }

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 100,
            tolerance: 10,
            amounts: [49.99],
            weights: [1],
        );

        foreach ($result['selected'] as $record) {
            $this->assertEquals(49.99, $record['assigned_amount']);
        }
    }

    public function test_select_and_assign_stops_when_target_reached(): void
    {
        $eligibleRows = [];
        for ($i = 0; $i < 1000; $i++) {
            $eligibleRows[] = [
                'row_index' => $i,
                'row' => [],
                'iban' => "DE{$i}",
            ];
        }

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 100,
            tolerance: 10,
            amounts: [9.99],
            weights: [1],
        );

        // Should NOT select all 1000 rows
        $this->assertLessThan(1000, count($result['selected']));
        $this->assertGreaterThanOrEqual(90, $result['achieved_amount']);
    }

    public function test_select_and_assign_achieved_amount_is_rounded(): void
    {
        $eligibleRows = [
            ['row_index' => 0, 'row' => [], 'iban' => 'DE1'],
            ['row_index' => 1, 'row' => [], 'iban' => 'DE2'],
            ['row_index' => 2, 'row' => [], 'iban' => 'DE3'],
        ];

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 30,
            tolerance: 5,
            amounts: [9.99],
            weights: [1],
        );

        // 9.99 * 3 = 29.97 — should be rounded to 2 decimal places
        $this->assertEquals(round($result['achieved_amount'], 2), $result['achieved_amount']);
    }

    public function test_select_and_assign_handles_insufficient_rows(): void
    {
        $eligibleRows = [
            ['row_index' => 0, 'row' => [], 'iban' => 'DE1'],
        ];

        $result = $this->service->selectAndAssignAmounts(
            eligibleRows: $eligibleRows,
            targetAmount: 5000,
            tolerance: 100,
            amounts: [9.99],
            weights: [1],
        );

        // Only 1 row available, can't reach target
        $this->assertCount(1, $result['selected']);
        $this->assertLessThan(5000, $result['achieved_amount']);
    }

    // ══════════════════════════════════════════════
    // loadPreviouslyUsedIbans()
    // ══════════════════════════════════════════════

    public function test_load_previously_used_ibans_returns_empty_when_no_batches(): void
    {
        $result = $this->service->loadPreviouslyUsedIbans();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_load_previously_used_ibans_returns_ibans_from_completed_batches(): void
    {
        $batch = $this->createBatch(['status' => 'completed']);

        FileGenerationRecord::insert([
            ['batch_id' => $batch->id, 'iban' => 'DE111', 'amount' => 9.99, 'source_row_index' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['batch_id' => $batch->id, 'iban' => 'DE222', 'amount' => 19.99, 'source_row_index' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->service->loadPreviouslyUsedIbans();

        $this->assertArrayHasKey('DE111', $result);
        $this->assertArrayHasKey('DE222', $result);
    }

    public function test_load_previously_used_ibans_ignores_non_completed_batches(): void
    {
        $pendingBatch = $this->createBatch(['status' => 'processing']);

        FileGenerationRecord::insert([
            ['batch_id' => $pendingBatch->id, 'iban' => 'DE333', 'amount' => 9.99, 'source_row_index' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->service->loadPreviouslyUsedIbans();

        $this->assertEmpty($result);
    }

    // ══════════════════════════════════════════════
    // loadRecentlyBilledIbans()
    // ══════════════════════════════════════════════

    public function test_load_recently_billed_ibans_returns_empty_when_no_attempts(): void
    {
        $result = $this->service->loadRecentlyBilledIbans();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_load_recently_billed_ibans_includes_recent_approved(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(5),
        ]);

        $result = $this->service->loadRecentlyBilledIbans(30);

        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', 'DE89370400440532013000'));
        $this->assertArrayHasKey($normalised, $result);
    }

    public function test_load_recently_billed_ibans_excludes_old_attempts(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_APPROVED,
            'created_at' => now()->subDays(60),
        ]);

        $result = $this->service->loadRecentlyBilledIbans(30);

        $this->assertEmpty($result);
    }

    public function test_load_recently_billed_ibans_excludes_voided_attempts(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'iban' => 'DE89370400440532013000',
        ]);

        BillingAttempt::factory()->create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'status' => BillingAttempt::STATUS_VOIDED,
            'created_at' => now()->subDays(5),
        ]);

        $result = $this->service->loadRecentlyBilledIbans(30);

        $this->assertEmpty($result);
    }

    // ══════════════════════════════════════════════
    // persistSelectedRecords()
    // ══════════════════════════════════════════════

    public function test_persist_selected_records_inserts_records(): void
    {
        $batch = $this->createBatch();

        $selected = [
            ['row_index' => 0, 'row' => [], 'iban' => 'DE111', 'assigned_amount' => 9.99],
            ['row_index' => 1, 'row' => [], 'iban' => 'DE222', 'assigned_amount' => 19.99],
            ['row_index' => 2, 'row' => [], 'iban' => 'DE333', 'assigned_amount' => 29.99],
        ];

        $this->service->persistSelectedRecords($batch, $selected);

        $this->assertDatabaseCount('file_generation_records', 3);
        $this->assertDatabaseHas('file_generation_records', [
            'batch_id' => $batch->id,
            'iban' => 'DE111',
            'amount' => 9.99,
            'source_row_index' => 0,
        ]);
        $this->assertDatabaseHas('file_generation_records', [
            'batch_id' => $batch->id,
            'iban' => 'DE333',
            'amount' => 29.99,
            'source_row_index' => 2,
        ]);
    }

    public function test_persist_selected_records_handles_empty_array(): void
    {
        $batch = $this->createBatch();

        $this->service->persistSelectedRecords($batch, []);

        $this->assertDatabaseCount('file_generation_records', 0);
    }

    public function test_persist_selected_records_handles_large_batch_in_chunks(): void
    {
        $batch = $this->createBatch();

        $selected = [];
        for ($i = 0; $i < 2500; $i++) {
            $selected[] = [
                'row_index' => $i,
                'row' => [],
                'iban' => "DE{$i}",
                'assigned_amount' => 9.99,
            ];
        }

        $this->service->persistSelectedRecords($batch, $selected);

        $this->assertDatabaseCount('file_generation_records', 2500);
    }

    // ══════════════════════════════════════════════
    // buildOutputHeaders()
    // ══════════════════════════════════════════════

    public function test_build_output_headers_adds_amount_column(): void
    {
        $headers = $this->service->buildOutputHeaders(
            ['first_name', 'last_name', 'iban'],
            bicInjected: false,
        );

        $this->assertContains('amount', $headers);
        $this->assertEquals(['first_name', 'last_name', 'iban', 'amount'], $headers);
    }

    public function test_build_output_headers_does_not_duplicate_amount(): void
    {
        $headers = $this->service->buildOutputHeaders(
            ['first_name', 'last_name', 'iban', 'amount'],
            bicInjected: false,
        );

        $amountCount = array_count_values($headers)['amount'];
        $this->assertEquals(1, $amountCount);
    }

    public function test_build_output_headers_adds_bic_when_injected(): void
    {
        $headers = $this->service->buildOutputHeaders(
            ['first_name', 'last_name', 'iban'],
            bicInjected: true,
        );

        $this->assertContains('bic', $headers);
        $this->assertContains('amount', $headers);
    }

    public function test_build_output_headers_without_bic_injection(): void
    {
        $headers = $this->service->buildOutputHeaders(
            ['first_name', 'last_name', 'iban'],
            bicInjected: false,
        );

        $this->assertNotContains('bic', $headers);
    }

    // ══════════════════════════════════════════════
    // writeOutputRow()
    // ══════════════════════════════════════════════

    public function test_write_output_row_writes_csv_line_with_amount(): void
    {
        $handle = fopen('php://memory', 'r+');

        $outputHeaders = ['first_name', 'last_name', 'iban', 'amount'];
        $row = ['first_name' => 'John', 'last_name' => 'Doe', 'iban' => 'DE123'];

        $this->service->writeOutputRow(
            $handle,
            $outputHeaders,
            $row,
            assignedAmount: 49.99,
            bicInjected: false,
        );

        rewind($handle);
        $line = fgets($handle);
        fclose($handle);

        $this->assertStringContainsString('John', $line);
        $this->assertStringContainsString('Doe', $line);
        $this->assertStringContainsString('DE123', $line);
        $this->assertStringContainsString('49.99', $line);
    }

    public function test_write_output_row_includes_bic_when_injected(): void
    {
        $handle = fopen('php://memory', 'r+');

        $outputHeaders = ['first_name', 'iban', 'bic', 'amount'];
        $row = ['first_name' => 'Jane', 'iban' => 'DE456'];

        $this->service->writeOutputRow(
            $handle,
            $outputHeaders,
            $row,
            assignedAmount: 29.99,
            bicInjected: true,
            resolvedBic: 'COBADEFFXXX',
        );

        rewind($handle);
        $line = fgets($handle);
        fclose($handle);

        $this->assertStringContainsString('COBADEFFXXX', $line);
        $this->assertStringContainsString('29.99', $line);
    }

    public function test_write_output_row_formats_amount_with_two_decimals(): void
    {
        $handle = fopen('php://memory', 'r+');

        $outputHeaders = ['amount'];
        $row = [];

        $this->service->writeOutputRow(
            $handle,
            $outputHeaders,
            $row,
            assignedAmount: 10.0,
            bicInjected: false,
        );

        rewind($handle);
        $line = fgets($handle);
        fclose($handle);

        $this->assertStringContainsString('10.00', $line);
    }

    public function test_write_output_row_handles_missing_row_keys(): void
    {
        $handle = fopen('php://memory', 'r+');

        $outputHeaders = ['first_name', 'last_name', 'missing_field', 'amount'];
        $row = ['first_name' => 'Alice'];

        $this->service->writeOutputRow(
            $handle,
            $outputHeaders,
            $row,
            assignedAmount: 9.99,
            bicInjected: false,
        );

        rewind($handle);
        $line = fgets($handle);
        fclose($handle);

        // Should still write a line — missing fields become empty string
        $this->assertStringContainsString('Alice', $line);
        $this->assertStringContainsString('9.99', $line);
    }

    // ══════════════════════════════════════════════
    // Constants
    // ══════════════════════════════════════════════

    public function test_price_ladder_has_ten_values(): void
    {
        $this->assertCount(10, FileGenerationService::PRICE_LADDER);
    }

    public function test_price_ladder_ranges_from_9_99_to_99_99(): void
    {
        $this->assertEquals(9.99, FileGenerationService::PRICE_LADDER[0]);
        $this->assertEquals(99.99, FileGenerationService::PRICE_LADDER[9]);
    }

    public function test_default_strategy_exists_in_strategies(): void
    {
        $this->assertArrayHasKey(
            FileGenerationService::DEFAULT_STRATEGY,
            FileGenerationService::PRICING_STRATEGIES,
        );
    }

    public function test_billing_inactivity_days_is_30(): void
    {
        $this->assertEquals(30, FileGenerationService::BILLING_INACTIVITY_DAYS);
    }
}
