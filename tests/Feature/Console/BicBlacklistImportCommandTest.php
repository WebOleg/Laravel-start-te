<?php

namespace Tests\Feature\Console;

use App\Models\BicBlacklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BicBlacklistImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/bic-blacklist-tests-' . time();
        File::makeDirectory($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_import_command_handles_asterisk_suffix_correctly(): void
    {
        $filePath = $this->createTestFile([
            'BDFE*',
            'DEUTDEFF',
            'CHAS*',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 3 entries in file')
            ->expectsOutputToContain('ADD: BDFE* (prefix)')
            ->expectsOutputToContain('ADD: DEUTDEFF')
            ->expectsOutputToContain('ADD: CHAS* (prefix)')
            ->expectsOutputToContain('Import completed: 3 created, 0 skipped')
            ->assertExitCode(0);

        // Verify BDFE* is stored as prefix
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'BDFE',
            'is_prefix' => true,
        ]);

        // Verify DEUTDEFF is stored as exact match
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'DEUTDEFF',
            'is_prefix' => false,
        ]);

        // Verify CHAS* is stored as prefix
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'CHAS',
            'is_prefix' => true,
        ]);
    }

    public function test_import_command_handles_windows_line_endings(): void
    {
        // Create file with \r\n (Windows line endings)
        $filePath = $this->tempDir . '/windows-line-endings.txt';
        File::put($filePath, "BDFE\r\nDEUTDEFF\r\nCHASUS33\r\n");

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 3 entries in file')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BDFE']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'DEUTDEFF']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'CHASUS33']);
    }

    public function test_import_command_handles_unix_line_endings(): void
    {
        // Create file with \n (Unix line endings)
        $filePath = $this->tempDir . '/unix-line-endings.txt';
        File::put($filePath, "BDFE\nDEUTDEFF\nCHASUS33\n");

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 3 entries in file')
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BDFE']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'DEUTDEFF']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'CHASUS33']);
    }

    public function test_import_command_is_case_insensitive(): void
    {
        $filePath = $this->createTestFile([
            'bdfefr',
            'DeUtDeFf',
            'CHASUS33',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        // All should be stored in uppercase
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BDFEFR']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'DEUTDEFF']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'CHASUS33']);
    }

    public function test_import_command_trims_whitespace(): void
    {
        $filePath = $this->createTestFile([
            '  BDFEFR  ',
            '	DEUTDEFF	',
            ' CHASUS33',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BDFEFR']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'DEUTDEFF']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'CHASUS33']);
    }

    public function test_import_command_skips_empty_lines(): void
    {
        $filePath = $this->createTestFile([
            'BDFEFR',
            '',
            'DEUTDEFF',
            '   ',
            'CHASUS33',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 3 entries in file')
            ->assertExitCode(0);

        $this->assertEquals(3, BicBlacklist::count());
    }

    public function test_import_command_skips_comment_lines(): void
    {
        $filePath = $this->createTestFile([
            '# This is a comment',
            'BDFEFR',
            '# Another comment',
            'DEUTDEFF',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 2 entries in file')
            ->assertExitCode(0);

        $this->assertEquals(2, BicBlacklist::count());
    }

    public function test_import_command_skips_duplicate_entries(): void
    {
        // Pre-create an entry
        BicBlacklist::create([
            'bic' => 'BDFEFR',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $filePath = $this->createTestFile([
            'BDFEFR',
            'DEUTDEFF',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutputToContain('SKIP: BDFEFR (exists)')
            ->expectsOutput('Import completed: 1 created, 1 skipped')
            ->assertExitCode(0);

        $this->assertEquals(2, BicBlacklist::count());
    }

    public function test_import_command_clear_option_removes_existing_entries(): void
    {
        // Pre-create some entries
        BicBlacklist::create([
            'bic' => 'OLD1',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'OLD2',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->assertEquals(2, BicBlacklist::count());

        $filePath = $this->createTestFile([
            'NEW1',
            'NEW2',
        ]);

        $this->artisan("bic-blacklist:import {$filePath} --clear")
            ->expectsOutputToContain('Cleared 2 existing entries')
            ->expectsOutput('Import completed: 2 created, 0 skipped')
            ->assertExitCode(0);

        // Old entries should be gone
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'OLD1']);
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'OLD2']);

        // New entries should exist
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'NEW1']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'NEW2']);

        $this->assertEquals(2, BicBlacklist::count());
    }

    public function test_import_command_dry_run_does_not_create_entries(): void
    {
        $filePath = $this->createTestFile([
            'BDFEFR',
            'DEUTDEFF',
        ]);

        $this->artisan("bic-blacklist:import {$filePath} --dry-run")
            ->expectsOutput('DRY RUN - no changes will be made')
            ->expectsOutputToContain('ADD: BDFEFR')
            ->expectsOutputToContain('ADD: DEUTDEFF')
            ->assertExitCode(0);

        $this->assertEquals(0, BicBlacklist::count());
    }

    public function test_import_command_dry_run_with_clear_does_not_delete(): void
    {
        BicBlacklist::create([
            'bic' => 'EXISTING',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $filePath = $this->createTestFile(['NEW1']);

        $this->artisan("bic-blacklist:import {$filePath} --dry-run --clear")
            ->expectsOutput('DRY RUN - no changes will be made')
            ->assertExitCode(0);

        // Existing entry should still be there
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'EXISTING']);
        $this->assertEquals(1, BicBlacklist::count());
    }

    public function test_import_command_sets_custom_reason(): void
    {
        $filePath = $this->createTestFile(['BDFEFR']);

        $this->artisan("bic-blacklist:import {$filePath} --reason=\"High fraud risk\"")
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'BDFEFR',
            'reason' => 'High fraud risk',
        ]);
    }

    public function test_import_command_sets_custom_source(): void
    {
        $filePath = $this->createTestFile(['BDFEFR']);

        $this->artisan("bic-blacklist:import {$filePath} --source=\"fraud-team\"")
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'BDFEFR',
            'source' => 'fraud-team',
        ]);
    }

    public function test_import_command_sets_blacklisted_by(): void
    {
        $filePath = $this->createTestFile(['BDFEFR']);

        $this->artisan("bic-blacklist:import {$filePath} --blacklisted-by=\"admin@example.com\"")
            ->assertExitCode(0);

        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'BDFEFR',
            'blacklisted_by' => 'admin@example.com',
        ]);
    }

    public function test_import_command_handles_nonexistent_file(): void
    {
        $this->artisan('bic-blacklist:import /path/that/does/not/exist.txt')
            ->expectsOutput('File not found: /path/that/does/not/exist.txt')
            ->assertExitCode(1);
    }

    public function test_import_command_handles_empty_file(): void
    {
        $filePath = $this->createTestFile([]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('No BIC codes found in file')
            ->assertExitCode(0);

        $this->assertEquals(0, BicBlacklist::count());
    }

    public function test_import_command_handles_asterisk_only_line(): void
    {
        $filePath = $this->createTestFile([
            'VALID*',
            '*',
            'ANOTHER',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        // Should create 2 entries (skip the lone *)
        $this->assertEquals(2, BicBlacklist::count());
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'VALID']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'ANOTHER']);
    }

    public function test_import_command_handles_mixed_exact_and_prefix(): void
    {
        $filePath = $this->createTestFile([
            'EXACT1',
            'PREFIX1*',
            'EXACT2',
            'PREFIX2*',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        // Verify exact matches
        $exact1 = BicBlacklist::where('bic', 'EXACT1')->first();
        $this->assertFalse($exact1->is_prefix);

        $exact2 = BicBlacklist::where('bic', 'EXACT2')->first();
        $this->assertFalse($exact2->is_prefix);

        // Verify prefix matches
        $prefix1 = BicBlacklist::where('bic', 'PREFIX1')->first();
        $this->assertTrue($prefix1->is_prefix);

        $prefix2 = BicBlacklist::where('bic', 'PREFIX2')->first();
        $this->assertTrue($prefix2->is_prefix);
    }

    public function test_import_command_differentiates_exact_and_prefix_duplicates(): void
    {
        // Pre-create exact match
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        // Try to import both exact and prefix version
        $filePath = $this->createTestFile([
            'BDFE',
            'BDFE*',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        // Should have both exact and prefix versions
        $this->assertEquals(2, BicBlacklist::where('bic', 'BDFE')->count());
        $this->assertEquals(1, BicBlacklist::where('bic', 'BDFE')->where('is_prefix', false)->count());
        $this->assertEquals(1, BicBlacklist::where('bic', 'BDFE')->where('is_prefix', true)->count());
    }

    public function test_import_command_with_large_file(): void
    {
        // Create a large file with 5000 entries
        $entries = [];
        for ($i = 1; $i <= 5000; $i++) {
            $entries[] = sprintf('BIC%04d', $i);
        }

        $filePath = $this->createTestFile($entries);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->expectsOutput('Found 5000 entries in file')
            ->expectsOutput('Import completed: 5000 created, 0 skipped')
            ->assertExitCode(0);

        $this->assertEquals(5000, BicBlacklist::count());
    }

    public function test_prefix_import_actually_matches_full_bics(): void
    {
        $filePath = $this->createTestFile([
            'BDFE*',
        ]);

        $this->artisan("bic-blacklist:import {$filePath}")
            ->assertExitCode(0);

        // Verify the prefix match works via the model
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFRPPXXX'));
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFR'));
        $this->assertFalse(BicBlacklist::isBlacklisted('BDF'));
    }

    public function test_import_realistic_production_scenario(): void
    {
        // Simulate receiving a new BIC list from user with mixed exact and prefix entries
        $filePath = $this->createTestFile([
            '# BIC Blacklist - 2026-02-11',
            '# High fraud BICs - exact matches',
            'BDFEFR',
            'DEUTDEFF',
            'CHASUS33',
            '',
            '# High fraud BIC prefixes - catch all variants',
            'BDFE*',
            'CHAS*',
            '',
            '# Additional flagged BICs',
            'BNPAFRPP',
            'SOGEFRPP*',
        ]);

        // Clear existing entries and import fresh list (typical production workflow)
        $this->artisan("bic-blacklist:import {$filePath} --clear --source=user-fraud-list --blacklisted-by=user@example.com")
            ->expectsOutputToContain('Cleared')
            ->expectsOutput('Found 7 entries in file')
            ->expectsOutput('Import completed: 7 created, 0 skipped')
            ->assertExitCode(0);

        // Verify all entries imported correctly
        $this->assertEquals(7, BicBlacklist::count());
        
        // Verify exact matches
        $this->assertEquals(4, BicBlacklist::where('is_prefix', false)->count());
        
        // Verify prefix matches
        $this->assertEquals(3, BicBlacklist::where('is_prefix', true)->count());
        
        // Verify source is set correctly
        $this->assertEquals(7, BicBlacklist::where('source', 'user-fraud-list')->count());
        
        // Verify blacklisted_by is set
        $this->assertEquals(7, BicBlacklist::where('blacklisted_by', 'user@example.com')->count());
        
        // Verify functional matching works
        $this->assertTrue(BicBlacklist::isBlacklisted('BDFEFRPPXXX')); // Matches BDFE*
        $this->assertTrue(BicBlacklist::isBlacklisted('CHASUS33')); // Matches exact AND CHAS*
        $this->assertTrue(BicBlacklist::isBlacklisted('SOGEFRPPXXX')); // Matches SOGEFRPP*
    }

    public function test_import_clear_flag_critical_for_production_updates(): void
    {
        // Simulate existing old blacklist
        BicBlacklist::create([
            'bic' => 'OLDBIC1',
            'is_prefix' => false,
            'source' => 'old-import',
        ]);
        BicBlacklist::create([
            'bic' => 'OLDBIC2',
            'is_prefix' => true,
            'source' => 'old-import',
        ]);
        
        // Create one auto-blacklisted entry (should also be cleared)
        BicBlacklist::create([
            'bic' => 'AUTOBIC',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_AUTO,
        ]);
        
        $this->assertEquals(3, BicBlacklist::count());
        
        // Import new list with --clear flag
        $filePath = $this->createTestFile([
            'NEWBIC1',
            'NEWBIC2*',
        ]);
        
        $this->artisan("bic-blacklist:import {$filePath} --clear")
            ->expectsOutputToContain('Cleared 3 existing entries')
            ->assertExitCode(0);
        
        // Verify old entries are completely gone
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'OLDBIC1']);
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'OLDBIC2']);
        $this->assertDatabaseMissing('bic_blacklists', ['bic' => 'AUTOBIC']);
        
        // Verify only new entries exist
        $this->assertEquals(2, BicBlacklist::count());
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'NEWBIC1']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'NEWBIC2']);
    }

    /**
     * Helper to create a test file with BIC entries.
     */
    private function createTestFile(array $lines): string
    {
        $filePath = $this->tempDir . '/test-' . uniqid() . '.txt';
        File::put($filePath, implode("\n", $lines));
        return $filePath;
    }
}
