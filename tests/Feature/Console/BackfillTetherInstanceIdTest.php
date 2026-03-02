<?php

/**
 * Feature tests for the tether:backfill-instance-id artisan command.
 */

namespace Tests\Feature\Console;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\DebtorProfile;
use App\Models\TetherInstance;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillTetherInstanceIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The create_tether_instances_table migration seeds a row with id=1.
        // Delete it so our factory creates a clean record at id=1.
        TetherInstance::query()->delete();
    }

    /** @test */
    public function test_backfill_sets_tether_instance_id_on_all_null_rows(): void
    {
        $instance = TetherInstance::factory()->create(['id' => 1]);

        $upload         = Upload::factory()->create(['tether_instance_id' => null]);
        $debtor_profile = DebtorProfile::factory()->create(['tether_instance_id' => null]);
        $debtor         = Debtor::factory()->create([
            'upload_id'         => $upload->id,
            'debtor_profile_id' => $debtor_profile->id,
            'tether_instance_id' => null,
        ]);
        $attempt        = BillingAttempt::factory()->create([
            'upload_id'          => $upload->id,
            'debtor_id'          => $debtor->id,
            'tether_instance_id' => null,
        ]);

        $this->artisan('tether:backfill-instance-id')->assertSuccessful();

        $this->assertEquals(1, $debtor->fresh()->tether_instance_id);
        $this->assertEquals(1, $upload->fresh()->tether_instance_id);
        $this->assertEquals(1, $debtor_profile->fresh()->tether_instance_id);
        $this->assertEquals(1, $attempt->fresh()->tether_instance_id);
    }

    public function test_dry_run_outputs_counts_without_writing(): void
    {
        TetherInstance::factory()->create(['id' => 1]);

        $upload  = Upload::factory()->create(['tether_instance_id' => null]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id, 'tether_instance_id' => null]);

        $this->artisan('tether:backfill-instance-id', ['--dry-run' => true])
            ->expectsOutputToContain('debtors')
            ->assertSuccessful();

        // Nothing written
        $this->assertNull($debtor->fresh()->tether_instance_id);
        $this->assertNull($upload->fresh()->tether_instance_id);
    }

    public function test_fails_when_tether_instance_id_1_does_not_exist(): void
    {
        // Ensure the table is empty
        TetherInstance::query()->delete();

        $this->artisan('tether:backfill-instance-id')
            ->expectsOutputToContain('not found')
            ->assertFailed();
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        TetherInstance::factory()->create(['id' => 1]);

        $upload = Upload::factory()->create(['tether_instance_id' => null]);
        Debtor::factory()->create(['upload_id' => $upload->id, 'tether_instance_id' => null]);

        $this->artisan('tether:backfill-instance-id')->assertSuccessful();
        $this->artisan('tether:backfill-instance-id')->assertSuccessful();

        // No rows should still be null
        $this->assertEquals(0, Debtor::whereNull('tether_instance_id')->count());
        $this->assertEquals(0, Upload::whereNull('tether_instance_id')->count());
    }
}
