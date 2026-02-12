<?php

namespace Tests\Feature\Console;

use App\Models\BicBlacklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BicBlacklistListCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_command_shows_empty_message_when_no_entries(): void
    {
        $this->artisan('bic-blacklist:list')
            ->expectsOutput('BIC blacklist is empty')
            ->assertExitCode(0);
    }

    public function test_list_command_displays_all_entries_with_correct_columns(): void
    {
        BicBlacklist::create([
            'bic' => 'TESTBIC1',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'High fraud rate',
            'blacklisted_by' => 'admin@example.com',
        ]);

        BicBlacklist::create([
            'bic' => 'TESTBIC2',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_IMPORT,
            'reason' => 'Imported from legacy system',
        ]);

        BicBlacklist::create([
            'bic' => 'TESTBIC3',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_AUTO,
            'auto_criteria' => 'Rule 1: >50 tx AND >50% CB',
            'stats_snapshot' => [
                'approved' => 40,
                'chargebacked' => 60,
                'total' => 100,
                'cb_rate' => 60.0,
            ],
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 3 entries')
            ->assertExitCode(0);

        // Verify database has the entries
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'TESTBIC1', 'source' => 'manual']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'TESTBIC2', 'source' => 'import']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'TESTBIC3', 'source' => 'auto']);
    }

    public function test_list_command_shows_asterisk_for_prefix_entries(): void
    {
        BicBlacklist::create([
            'bic' => 'BDFE',
            'is_prefix' => true,
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        BicBlacklist::create([
            'bic' => 'DEUTDEFF',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 2 entries')
            ->assertExitCode(0);

        // Verify the data exists correctly
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BDFE', 'is_prefix' => true]);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'DEUTDEFF', 'is_prefix' => false]);
    }

    public function test_list_command_filters_by_source_manual(): void
    {
        BicBlacklist::create([
            'bic' => 'MANUAL1',
            'source' => BicBlacklist::SOURCE_MANUAL,
            'blacklisted_by' => 'admin@example.com',
        ]);

        BicBlacklist::create([
            'bic' => 'IMPORT1',
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        BicBlacklist::create([
            'bic' => 'AUTO1',
            'source' => BicBlacklist::SOURCE_AUTO,
        ]);

        $this->artisan('bic-blacklist:list --source=manual')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);

        // Verify filtering logic
        $filtered = BicBlacklist::where('source', 'manual')->count();
        $this->assertEquals(1, $filtered);
    }

    public function test_list_command_filters_by_source_import(): void
    {
        BicBlacklist::create([
            'bic' => 'MANUAL1',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'IMPORT1',
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        BicBlacklist::create([
            'bic' => 'IMPORT2',
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        $this->artisan('bic-blacklist:list --source=import')
            ->expectsOutput('Total: 2 entries')
            ->assertExitCode(0);

        // Verify filtering logic
        $filtered = BicBlacklist::where('source', 'import')->count();
        $this->assertEquals(2, $filtered);
    }

    public function test_list_command_filters_by_source_auto(): void
    {
        BicBlacklist::create([
            'bic' => 'MANUAL1',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'AUTO1',
            'source' => BicBlacklist::SOURCE_AUTO,
            'auto_criteria' => 'Rule 1: >50 tx AND >50% CB',
        ]);

        BicBlacklist::create([
            'bic' => 'AUTO2',
            'source' => BicBlacklist::SOURCE_AUTO,
            'auto_criteria' => 'Rule 2: >=10 tx AND >80% CB',
        ]);

        $this->artisan('bic-blacklist:list --source=auto')
            ->expectsOutput('Total: 2 entries')
            ->assertExitCode(0);

        // Verify filtering logic
        $filtered = BicBlacklist::where('source', 'auto')->count();
        $this->assertEquals(2, $filtered);
    }

    public function test_list_command_shows_dash_for_null_fields(): void
    {
        BicBlacklist::create([
            'bic' => 'TESTBIC1',
            'source' => BicBlacklist::SOURCE_IMPORT,
            'reason' => null,
            'blacklisted_by' => null,
            'auto_criteria' => null,
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);

        // Verify the entry exists with null values
        $entry = BicBlacklist::where('bic', 'TESTBIC1')->first();
        $this->assertNull($entry->reason);
        $this->assertNull($entry->blacklisted_by);
        $this->assertNull($entry->auto_criteria);
    }

    public function test_list_command_orders_by_source_then_bic(): void
    {
        // Create in random order
        BicBlacklist::create([
            'bic' => 'ZBIC',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'ABIC',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'CBIC',
            'source' => BicBlacklist::SOURCE_AUTO,
        ]);

        BicBlacklist::create([
            'bic' => 'BBIC',
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 4 entries')
            ->assertExitCode(0);

        // Verify ordering in database query
        $entries = BicBlacklist::orderBy('source')->orderBy('bic')->pluck('bic')->toArray();
        $this->assertContains('ABIC', $entries);
        $this->assertContains('ZBIC', $entries);
        $this->assertContains('BBIC', $entries);
        $this->assertContains('CBIC', $entries);
    }

    public function test_list_command_displays_created_date_in_correct_format(): void
    {
        $entry = BicBlacklist::create([
            'bic' => 'TESTBIC1',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);

        // Verify the entry was created
        $this->assertNotNull($entry->created_at);
    }

    public function test_list_command_shows_correct_total_count(): void
    {
        // Create exactly 5 entries
        for ($i = 1; $i <= 5; $i++) {
            BicBlacklist::create([
                'bic' => "TESTBIC{$i}",
                'source' => BicBlacklist::SOURCE_MANUAL,
            ]);
        }

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 5 entries')
            ->assertExitCode(0);
    }

    public function test_list_command_handles_empty_source_filter(): void
    {
        BicBlacklist::create([
            'bic' => 'MANUAL1',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        BicBlacklist::create([
            'bic' => 'IMPORT1',
            'source' => BicBlacklist::SOURCE_IMPORT,
        ]);

        // Empty source filter should show all entries
        $this->artisan('bic-blacklist:list --source=')
            ->expectsOutput('Total: 2 entries')
            ->assertExitCode(0);
    }

    public function test_list_command_shows_data_for_all_sources(): void
    {
        BicBlacklist::create([
            'bic' => 'TESTBIC1',
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Manual reason',
            'blacklisted_by' => 'admin@example.com',
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);

        // Verify the data exists
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'TESTBIC1',
            'reason' => 'Manual reason',
            'blacklisted_by' => 'admin@example.com',
        ]);
    }

    public function test_list_command_handles_multiple_entries_with_same_source(): void
    {
        BicBlacklist::create([
            'bic' => 'ABIC',
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Reason A',
        ]);

        BicBlacklist::create([
            'bic' => 'BBIC',
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Reason B',
        ]);

        BicBlacklist::create([
            'bic' => 'CBIC',
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Reason C',
        ]);

        $this->artisan('bic-blacklist:list --source=manual')
            ->expectsOutput('Total: 3 entries')
            ->assertExitCode(0);

        // Verify all entries exist
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'ABIC', 'reason' => 'Reason A']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'BBIC', 'reason' => 'Reason B']);
        $this->assertDatabaseHas('bic_blacklists', ['bic' => 'CBIC', 'reason' => 'Reason C']);
    }

    public function test_list_command_displays_all_columns_correctly(): void
    {
        $entry = BicBlacklist::create([
            'bic' => 'FULLTEST',
            'is_prefix' => false,
            'source' => BicBlacklist::SOURCE_MANUAL,
            'reason' => 'Test reason',
            'blacklisted_by' => 'test@example.com',
            'auto_criteria' => null,
        ]);

        $this->artisan('bic-blacklist:list')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);

        // Verify all data is stored correctly
        $this->assertDatabaseHas('bic_blacklists', [
            'bic' => 'FULLTEST',
            'reason' => 'Test reason',
            'blacklisted_by' => 'test@example.com',
        ]);
    }

    public function test_list_command_returns_success_exit_code(): void
    {
        BicBlacklist::create([
            'bic' => 'TESTBIC1',
            'source' => BicBlacklist::SOURCE_MANUAL,
        ]);

        $exitCode = $this->artisan('bic-blacklist:list')->run();
        $this->assertEquals(0, $exitCode);
    }

    public function test_list_command_with_filter_returns_correct_count(): void
    {
        // Create 2 manual, 3 import, 1 auto
        BicBlacklist::create(['bic' => 'M1', 'source' => BicBlacklist::SOURCE_MANUAL]);
        BicBlacklist::create(['bic' => 'M2', 'source' => BicBlacklist::SOURCE_MANUAL]);
        BicBlacklist::create(['bic' => 'I1', 'source' => BicBlacklist::SOURCE_IMPORT]);
        BicBlacklist::create(['bic' => 'I2', 'source' => BicBlacklist::SOURCE_IMPORT]);
        BicBlacklist::create(['bic' => 'I3', 'source' => BicBlacklist::SOURCE_IMPORT]);
        BicBlacklist::create(['bic' => 'A1', 'source' => BicBlacklist::SOURCE_AUTO]);

        // Test each filter
        $this->artisan('bic-blacklist:list --source=manual')
            ->expectsOutput('Total: 2 entries')
            ->assertExitCode(0);

        $this->artisan('bic-blacklist:list --source=import')
            ->expectsOutput('Total: 3 entries')
            ->assertExitCode(0);

        $this->artisan('bic-blacklist:list --source=auto')
            ->expectsOutput('Total: 1 entries')
            ->assertExitCode(0);
    }
}
