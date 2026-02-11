<?php

namespace Tests\Unit\Models;

use App\Models\BicBlacklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BicBlacklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_bic_match_returns_true(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFEFR1234XXXX',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFR1234XXXX'));
    }

    public function test_exact_bic_match_is_case_insensitive(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertTrue(BicBlacklist::isBlacklisted('bdfefr'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BdFeFr'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFR'));
    }

    public function test_prefix_match_catches_full_bic(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        // Should match any BIC starting with BDFE
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFRPPXXX'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFR'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFE123'));
    }

    public function test_prefix_match_is_case_insensitive(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertTrue(BicBlacklist::isBlacklisted('bdfefrppxxx'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BdfeFrPpXxX'));
    }

    public function test_prefix_match_does_not_match_partial_prefix(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        // Should NOT match - BDF is not BDFE
        $this->assertFalse(BicBlacklist::isBlacklisted('BDF'));
        $this->assertFalse(BicBlacklist::isBlacklisted('BD'));
    }

    public function test_non_matching_bic_returns_false(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertFalse(BicBlacklist::isBlacklisted('DEUTDEFF'));
        $this->assertFalse(BicBlacklist::isBlacklisted('CHASUS33'));
    }

    public function test_empty_bic_returns_false(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertFalse(BicBlacklist::isBlacklisted(''));
        $this->assertFalse(BicBlacklist::isBlacklisted('   '));
    }

    public function test_whitespace_is_trimmed(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertTrue(BicBlacklist::isBlacklisted('  BDFEFR  '));
    }

    public function test_multiple_prefix_entries_all_checked(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'DEUT',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'CHAS',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFRPPXXX'));
        $this->assertTrue(BicBlacklist::isBlacklisted('DEUTDEFF'));
        $this->assertTrue(BicBlacklist::isBlacklisted('CHASUS33'));
        $this->assertFalse(BicBlacklist::isBlacklisted('BNPAFRPP'));
    }

    public function test_exact_match_takes_precedence_over_prefix(): void
    {
        // Both exact and prefix exist - exact should be checked first
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        // Both should match
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFR'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFRPPXXX'));
    }

    public function test_stats_snapshot_is_stored_as_array(): void
    {
        $stats = [
            'approved' => 100,
            'chargebacked' => 60,
            'total' => 160,
            'cb_rate' => 37.5,
            'period_days' => 30,
        ];

        $entry = BicBlacklist::create([
            'bic' => 'TESTBIC',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_AUTO,
            'stats_snapshot' => $stats,
        ]);

        $this->assertIsArray($entry->stats_snapshot);
        $this->assertEquals(100, $entry->stats_snapshot['approved']);
        $this->assertEquals(60, $entry->stats_snapshot['chargebacked']);
        $this->assertEquals(37.5, $entry->stats_snapshot['cb_rate']);
    }

    public function test_source_constants_are_defined(): void
    {
        $this->assertEquals('manual', BicBlacklist::SOURCE_MANUAL);
        $this->assertEquals('import', BicBlacklist::SOURCE_IMPORT);
        $this->assertEquals('auto', BicBlacklist::SOURCE_AUTO);
    }

    public function test_is_prefix_is_cast_to_boolean(): void
    {
        $entry = BicBlacklist::create([
            'bic' => 'TESTBIC',
            'is_prefix' => 1,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertIsBool($entry->is_prefix);
        $this->assertTrue($entry->is_prefix);

        $entry2 = BicBlacklist::create([
            'bic' => 'TESTBIC2',
            'is_prefix' => 0,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertIsBool($entry2->is_prefix);
        $this->assertFalse($entry2->is_prefix);
    }
}
