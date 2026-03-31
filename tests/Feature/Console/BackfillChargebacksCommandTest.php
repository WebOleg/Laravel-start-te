<?php

/**
 * Feature tests for the chargebacks:backfill artisan command.
 */

namespace Tests\Feature\Console;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillChargebacksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_inserts_missing_chargebacks(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $attempt = BillingAttempt::factory()->create([
            'upload_id'                     => $upload->id,
            'debtor_id'                     => $debtor->id,
            'status'                        => BillingAttempt::STATUS_CHARGEBACKED,
            'unique_id'                     => 'test-unique-id-001',
            'amount'                        => 49.99,
            'currency'                      => 'EUR',
            'chargeback_reason_code'        => 'MS02',
            'chargeback_reason_description' => 'Not Specified',
            'chargebacked_at'               => now(),
        ]);

        $this->artisan('chargebacks:backfill')->assertSuccessful();

        $this->assertDatabaseHas('chargebacks', [
            'billing_attempt_id'             => $attempt->id,
            'debtor_id'                      => $debtor->id,
            'original_transaction_unique_id' => 'test-unique-id-001',
            'reason_code'                    => 'MS02',
            'source'                         => 'backfill',
        ]);
    }

    public function test_backfill_skips_already_existing_chargebacks(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $attempt = BillingAttempt::factory()->create([
            'upload_id'       => $upload->id,
            'debtor_id'       => $debtor->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'unique_id'       => 'test-unique-id-002',
            'amount'          => 99.00,
            'currency'        => 'EUR',
            'chargebacked_at' => now(),
        ]);

        DB::table('chargebacks')->insert([
            'billing_attempt_id'             => $attempt->id,
            'debtor_id'                      => $debtor->id,
            'original_transaction_unique_id' => 'test-unique-id-002',
            'type'                           => '1st chargeback',
            'chargeback_amount'              => 99.00,
            'source'                         => 'api_sync',
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);

        $this->artisan('chargebacks:backfill')->assertSuccessful();

        $this->assertSame(1, DB::table('chargebacks')->where('billing_attempt_id', $attempt->id)->count());
    }

    public function test_dry_run_does_not_insert(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id'       => $upload->id,
            'debtor_id'       => $debtor->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'unique_id'       => 'test-unique-id-003',
            'amount'          => 25.00,
            'currency'        => 'EUR',
            'chargebacked_at' => now(),
        ]);

        $this->artisan('chargebacks:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('chargebacks', 0);
    }

    public function test_backfill_is_idempotent(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id'       => $upload->id,
            'debtor_id'       => $debtor->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'unique_id'       => 'test-unique-id-004',
            'amount'          => 75.00,
            'currency'        => 'EUR',
            'chargebacked_at' => now(),
        ]);

        $this->artisan('chargebacks:backfill')->assertSuccessful();
        $this->artisan('chargebacks:backfill')->assertSuccessful();

        $this->assertSame(1, DB::table('chargebacks')->count());
    }

    public function test_limit_option_restricts_processed_records(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'       => $upload->id,
            'debtor_id'       => $debtor->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'chargebacked_at' => now(),
        ]);

        $this->artisan('chargebacks:backfill', ['--limit' => 3])->assertSuccessful();

        $this->assertSame(3, DB::table('chargebacks')->count());
    }

    public function test_skips_billing_attempts_without_unique_id(): void
    {
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->create([
            'upload_id'       => $upload->id,
            'debtor_id'       => $debtor->id,
            'status'          => BillingAttempt::STATUS_CHARGEBACKED,
            'unique_id'       => null,
            'chargebacked_at' => now(),
        ]);

        $this->artisan('chargebacks:backfill')->assertSuccessful();

        $this->assertDatabaseCount('chargebacks', 0);
    }
}
