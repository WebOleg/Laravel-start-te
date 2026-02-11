<?php

namespace Tests\Feature\Console;

use App\Models\BicBlacklist;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BicBlacklistAutoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set config for excluded chargeback codes
        config(['tether.chargeback.excluded_cb_reason_codes' => ['XT33', 'XT73']]);
    }

    public function test_command_blacklists_bic_with_rule_1_criteria(): void
    {
        // Rule 1: >50 transactions AND >50% chargeback rate
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create 40 approved + 51 chargebacked = 91 total, 56.04% chargeback rate
        $this->createBillingAttempts($debtor, 'TESTBIC1', 40, 51);

        $this->artisan('bic-blacklist:auto --period=30')
            ->expectsOutput('Analyzing BICs for last 30 days...')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'TESTBIC1',
            'source' => BicBlacklist::SOURCE_AUTO,
            'auto_criteria' => 'Rule 1: >50 tx AND >50% CB',
        ]);

        $entry = BicBlacklist::where('bic', 'TESTBIC1')->first();
        $this->assertNotNull($entry->stats_snapshot);
        $this->assertEquals(40, $entry->stats_snapshot['approved']);
        $this->assertEquals(51, $entry->stats_snapshot['chargebacked']);
        $this->assertEquals(91, $entry->stats_snapshot['total']);
        $this->assertEqualsWithDelta(56.04, $entry->stats_snapshot['cb_rate'], 0.1);
    }

    public function test_command_blacklists_bic_with_rule_2_criteria(): void
    {
        // Rule 2: ≥10 transactions AND >80% chargeback rate
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create 2 approved + 9 chargebacked = 11 total, 81.82% chargeback rate
        $this->createBillingAttempts($debtor, 'TESTBIC2', 2, 9);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'TESTBIC2',
            'source' => BicBlacklist::SOURCE_AUTO,
            'auto_criteria' => 'Rule 2: >=10 tx AND >80% CB',
        ]);

        $entry = BicBlacklist::where('bic', 'TESTBIC2')->first();
        $this->assertEquals(2, $entry->stats_snapshot['approved']);
        $this->assertEquals(9, $entry->stats_snapshot['chargebacked']);
        $this->assertEquals(11, $entry->stats_snapshot['total']);
    }

    public function test_command_stores_stats_snapshot_with_correct_fields(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Rule 1 criteria
        $this->createBillingAttempts($debtor, 'TESTBIC3', 30, 40);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $entry = BicBlacklist::where('bic', 'TESTBIC3')->first();

        // Verify all required fields are present
        $this->assertArrayHasKey('approved', $entry->stats_snapshot);
        $this->assertArrayHasKey('chargebacked', $entry->stats_snapshot);
        $this->assertArrayHasKey('total', $entry->stats_snapshot);
        $this->assertArrayHasKey('cb_rate', $entry->stats_snapshot);
        $this->assertArrayHasKey('period_days', $entry->stats_snapshot);
        $this->assertArrayHasKey('calculated_at', $entry->stats_snapshot);

        // Verify values
        $this->assertEquals(30, $entry->stats_snapshot['approved']);
        $this->assertEquals(40, $entry->stats_snapshot['chargebacked']);
        $this->assertEquals(70, $entry->stats_snapshot['total']);
        $this->assertEquals(30, $entry->stats_snapshot['period_days']);
        $this->assertNotEmpty($entry->stats_snapshot['calculated_at']);
    }

    public function test_command_does_not_blacklist_bic_below_rule_1_threshold(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // 51 total but only 50% chargeback rate (needs >50%)
        $this->createBillingAttempts($debtor, 'TESTBIC4', 26, 25);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'TESTBIC4',
        ]);
    }

    public function test_command_boundary_exactly_50_transactions(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Exactly 50 total transactions (needs >50, not >=50) with 51% CB
        $this->createBillingAttempts($debtor, 'BOUNDARY1', 24, 26);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should NOT be blacklisted (50 is not >50)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'BOUNDARY1',
        ]);
    }

    public function test_command_boundary_exactly_50_percent_chargeback(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // 60 transactions with exactly 50% CB (needs >50%)
        $this->createBillingAttempts($debtor, 'BOUNDARY2', 30, 30);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should NOT be blacklisted (50% is not >50%)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'BOUNDARY2',
        ]);
    }

    public function test_command_boundary_exactly_10_transactions(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Exactly 9 transactions (needs >=10) with 89% CB rate
        // Should NOT be blacklisted because 9 < 10
        $this->createBillingAttempts($debtor, 'BOUNDARY3', 1, 8); // 9 total, 88.89% CB

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should NOT be blacklisted (9 transactions is not >=10)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'BOUNDARY3',
        ]);

        // Now test with exactly 10 transactions and 81% CB rate
        // Should BE blacklisted because 10 >= 10 and 81% > 80%
        $this->createBillingAttempts($debtor, 'BOUNDARY4', 2, 9); // 11 total, 81.82% CB

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // SHOULD be blacklisted (11 >= 10 and 81.82% > 80%)
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'BOUNDARY4',
        ]);
    }

    public function test_command_boundary_exactly_80_percent_chargeback(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // 10 transactions with exactly 80% CB (needs >80%)
        $this->createBillingAttempts($debtor, 'BOUNDARY5', 2, 8);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should NOT be blacklisted (80% is not >80%)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'BOUNDARY5',
        ]);
    }

    public function test_command_is_idempotent_can_run_multiple_times_safely(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create data that triggers Rule 1
        $this->createBillingAttempts($debtor, 'IDEMPOTENT', 30, 40);

        // Run command first time
        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'IDEMPOTENT']);
        $countAfterFirst = BicBlacklist::where('bic', 'IDEMPOTENT')->count();
        $this->assertEquals(1, $countAfterFirst);

        // Run command second time (should be idempotent)
        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should still be only 1 entry
        $countAfterSecond = BicBlacklist::where('bic', 'IDEMPOTENT')->count();
        $this->assertEquals(1, $countAfterSecond);

        // Run third time to be sure
        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $countAfterThird = BicBlacklist::where('bic', 'IDEMPOTENT')->count();
        $this->assertEquals(1, $countAfterThird);
    }

    public function test_command_handles_zero_transactions_gracefully(): void
    {
        // Don't create any billing attempts
        $countBefore = BicBlacklist::count();

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // No new entries should be created
        $this->assertEquals($countBefore, BicBlacklist::count());
    }

    public function test_command_chargeback_rate_calculation_precision(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create scenario with precise calculation: 33 approved + 67 CB = 100 total, 67.00% CB
        $this->createBillingAttempts($debtor, 'PRECISION1', 33, 67);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $entry = BicBlacklist::where('bic', 'PRECISION1')->first();
        $this->assertNotNull($entry);
        $this->assertEquals(67.0, $entry->stats_snapshot['cb_rate']);
    }

    public function test_command_does_not_blacklist_bic_below_rule_2_threshold(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // 10 total but only 80% chargeback rate (needs >80%)
        $this->createBillingAttempts($debtor, 'TESTBIC5', 2, 8);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'TESTBIC5',
        ]);
    }

    public function test_command_excludes_xt33_and_xt73_from_chargeback_count(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create 10 approved
        $this->createBillingAttempts($debtor, 'TESTBIC6', 10, 0);

        // Create 50 chargebacks with XT33 (should be excluded)
        for ($i = 0; $i < 50; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => 'TESTBIC6',
                'chargeback_reason_code' => 'XT33',
                'emp_created_at' => now()->subDays(5),
            ]);
        }

        // Create 5 chargebacks with XT73 (should be excluded)
        for ($i = 0; $i < 5; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => 'TESTBIC6',
                'chargeback_reason_code' => 'XT73',
                'emp_created_at' => now()->subDays(5),
            ]);
        }

        // Create 5 chargebacks with normal reason code (should be counted)
        for ($i = 0; $i < 5; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => 'TESTBIC6',
                'chargeback_reason_code' => 'AC04',
                'emp_created_at' => now()->subDays(5),
            ]);
        }

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should NOT be blacklisted: 10 approved + 5 chargebacked = 15 total, 33.33% rate
        // (XT33 and XT73 chargebacks should be excluded from count)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'TESTBIC6',
        ]);
    }

    public function test_command_skips_already_blacklisted_bic(): void
    {
        // Pre-blacklist the BIC
        BicBlacklist::create([
            'bic' => 'TESTBIC7',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Manual blacklist',
        ]);

        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create data that would match Rule 1
        $this->createBillingAttempts($debtor, 'TESTBIC7', 30, 40);

        $countBefore = BicBlacklist::where('bic', 'TESTBIC7')->count();

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Should still be only 1 entry (not duplicated)
        $this->assertEquals($countBefore, BicBlacklist::where('bic', 'TESTBIC7')->count());
    }

    public function test_command_respects_period_option(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create old data (outside 7-day window)
        for ($i = 0; $i < 60; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => 'TESTBIC8',
                'emp_created_at' => now()->subDays(10),
            ]);
        }

        // Run with 7-day period
        $this->artisan('bic-blacklist:auto --period=7')
            ->assertExitCode(0);

        // Should NOT be blacklisted (data is outside 7-day window)
        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'TESTBIC8',
        ]);

        // Now create recent data
        $this->createBillingAttempts($debtor, 'TESTBIC9', 30, 40, now()->subDays(3));

        $this->artisan('bic-blacklist:auto --period=7')
            ->assertExitCode(0);

        // Should be blacklisted (data is within 7-day window)
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'TESTBIC9',
        ]);
    }

    public function test_command_dry_run_does_not_create_entries(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        $this->createBillingAttempts($debtor, 'TESTBIC10', 30, 40);

        $this->artisan('bic-blacklist:auto --dry-run')
            ->expectsOutput('DRY RUN - no changes will be made')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('bic_blacklists', [
            'bic' => 'TESTBIC10',
        ]);
    }

    public function test_command_handles_multiple_bics(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // BIC that meets Rule 1
        $this->createBillingAttempts($debtor, 'MULTI1', 30, 40);

        // BIC that meets Rule 2
        $this->createBillingAttempts($debtor, 'MULTI2', 2, 9);

        // BIC that doesn't meet any criteria
        $this->createBillingAttempts($debtor, 'MULTI3', 50, 10);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'MULTI1']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'MULTI2']);
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'MULTI3']);
    }

    public function test_command_ignores_null_or_empty_bic(): void
    {
        $profile = DebtorProfile::factory()->create();
        $debtor = Debtor::factory()->create(['debtor_profile_id' => $profile->id]);

        // Create attempts with null/empty BIC
        for ($i = 0; $i < 60; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => $i < 30 ? null : '',
                'emp_created_at' => now()->subDays(5),
            ]);
        }

        $countBefore = BicBlacklist::count();

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // No new entries should be created
        $this->assertEquals($countBefore, BicBlacklist::count());
    }

    public function test_command_applies_to_any_country(): void
    {
        // Create debtors from different countries
        $profileFR = DebtorProfile::factory()->create(['billing_model' => 'flywheel']);
        $debtorFR = Debtor::factory()->create([
            'debtor_profile_id' => $profileFR->id,
            'country' => 'FR',
        ]);

        $profileDE = DebtorProfile::factory()->create(['billing_model' => 'recovery']);
        $debtorDE = Debtor::factory()->create([
            'debtor_profile_id' => $profileDE->id,
            'country' => 'DE',
        ]);

        // Both should trigger Rule 1
        $this->createBillingAttempts($debtorFR, 'BICFR', 30, 40);
        $this->createBillingAttempts($debtorDE, 'BICDE', 30, 40);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Both should be blacklisted regardless of country
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BICFR']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BICDE']);
    }

    public function test_command_applies_to_any_account(): void
    {
        $profile1 = DebtorProfile::factory()->create();
        $debtor1 = Debtor::factory()->create(['debtor_profile_id' => $profile1->id]);

        $profile2 = DebtorProfile::factory()->create();
        $debtor2 = Debtor::factory()->create(['debtor_profile_id' => $profile2->id]);

        // Both should trigger Rule 1
        $this->createBillingAttempts($debtor1, 'BICACC1', 30, 40);
        $this->createBillingAttempts($debtor2, 'BICACC2', 30, 40);

        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);

        // Both should be blacklisted regardless of account
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BICACC1']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BICACC2']);
    }

    public function test_command_critical_daily_cron_simulation(): void
    {
        // Simulate a realistic daily cron run with mixed data
        $profiles = DebtorProfile::factory()->count(3)->create();
        
        // Day 1: High-risk BICs that should be caught
        $debtor1 = Debtor::factory()->create(['debtor_profile_id' => $profiles[0]->id]);
        $this->createBillingAttempts($debtor1, 'HIGHRISK1', 40, 55); // 57.89% CB, triggers Rule 1
        
        $debtor2 = Debtor::factory()->create(['debtor_profile_id' => $profiles[1]->id]);
        $this->createBillingAttempts($debtor2, 'HIGHRISK2', 2, 10); // 83.33% CB, triggers Rule 2
        
        // Normal/safe BICs that should NOT be caught
        $debtor3 = Debtor::factory()->create(['debtor_profile_id' => $profiles[2]->id]);
        $this->createBillingAttempts($debtor3, 'SAFEBIC1', 100, 10); // 9.09% CB, safe
        $this->createBillingAttempts($debtor3, 'SAFEBIC2', 45, 5); // 10% CB, safe
        
        // Run the daily cron job
        $this->artisan('bic-blacklist:auto --period=30')
            ->assertExitCode(0);
        
        // Verify only high-risk BICs were blacklisted
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'HIGHRISK1',
            'source' => BicBlacklist::SOURCE_AUTO,
        ]);
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'HIGHRISK2',
            'source' => BicBlacklist::SOURCE_AUTO,
        ]);
        
        // Verify safe BICs were NOT blacklisted
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'SAFEBIC1']);
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'SAFEBIC2']);
        
        // Verify exactly 2 auto-blacklisted entries created
        $this->assertEquals(2, BicBlacklist::where('source', BicBlacklist::SOURCE_AUTO)->count());
    }

    /**
     * Helper to create billing attempts with specified approved and chargeback counts.
     */
    private function createBillingAttempts(
        Debtor $debtor,
        string $bic,
        int $approved,
        int $chargebacked,
        $date = null
    ): void {
        $date = $date ?? now()->subDays(5);

        for ($i = 0; $i < $approved; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_APPROVED,
                'bic' => $bic,
                'emp_created_at' => $date,
            ]);
        }

        for ($i = 0; $i < $chargebacked; $i++) {
            BillingAttempt::factory()->create([
                'debtor_id' => $debtor->id,
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'bic' => $bic,
                'chargeback_reason_code' => 'AC04', // Normal chargeback reason
                'emp_created_at' => $date,
            ]);
        }
    }
}
