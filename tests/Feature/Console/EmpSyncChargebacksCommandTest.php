<?php

/**
 * Feature tests for emp:sync-chargebacks artisan command cb rate summary.
 */

namespace Tests\Feature\Console;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\EmpAccount;
use App\Models\Upload;
use App\Services\Emp\EmpChargebackSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EmpSyncChargebacksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_shows_cb_rate_summary_after_sync(): void
    {
        $this->mock(EmpChargebackSyncService::class, function ($mock) {
            $mock->shouldReceive('syncByDate')
                ->once()
                ->andReturn([
                    'total_fetched'     => 0,
                    'matched'           => 0,
                    'already_processed' => 0,
                    'unmatched'         => 0,
                    'errors'            => 0,
                    'blacklisted'       => 0,
                ]);
        });

        $account = EmpAccount::factory()->create(['name' => 'Optivest', 'is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 100.00,
            'created_at'     => now()->subDays(3),
        ]);

        $this->artisan('emp:sync-chargebacks', ['--date' => now()->subDay()->format('Y-m-d')])
            ->expectsOutputToContain('Chargeback Rate Summary')
            ->expectsOutputToContain('Optivest')
            ->assertExitCode(0);
    }

    public function test_command_logs_critical_when_cb_rate_exceeds_critical_threshold(): void
    {
        Log::spy();

        $this->mock(EmpChargebackSyncService::class, function ($mock) {
            $mock->shouldReceive('syncByDate')
                ->once()
                ->andReturn([
                    'total_fetched'     => 0,
                    'matched'           => 0,
                    'already_processed' => 0,
                    'unmatched'         => 0,
                    'errors'            => 0,
                    'blacklisted'       => 0,
                ]);
        });

        $account = EmpAccount::factory()->create(['name' => 'Vinci Payments FR', 'is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 100.00,
            'created_at'     => now()->subDays(3),
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'amount'         => 100.00,
            'created_at'     => now()->subDays(3),
        ]);

        $this->artisan('emp:sync-chargebacks', ['--date' => now()->subDay()->format('Y-m-d')])
            ->assertExitCode(0);

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_command_skips_cb_summary_in_dry_run_mode(): void
    {
        $this->mock(EmpChargebackSyncService::class, function ($mock) {
            $mock->shouldReceive('syncByDate')
                ->once()
                ->andReturn([
                    'total_fetched'     => 0,
                    'matched'           => 0,
                    'already_processed' => 0,
                    'unmatched'         => 0,
                    'errors'            => 0,
                    'blacklisted'       => 0,
                ]);
        });

        $this->artisan('emp:sync-chargebacks', [
            '--date'    => now()->subDay()->format('Y-m-d'),
            '--dry-run' => true,
        ])
            ->doesntExpectOutputToContain('Chargeback Rate Summary')
            ->assertExitCode(0);
    }

    public function test_command_returns_failure_on_sync_errors(): void
    {
        $this->mock(EmpChargebackSyncService::class, function ($mock) {
            $mock->shouldReceive('syncByDate')
                ->once()
                ->andReturn([
                    'total_fetched'     => 5,
                    'matched'           => 3,
                    'already_processed' => 0,
                    'unmatched'         => 0,
                    'errors'            => 2,
                    'blacklisted'       => 0,
                ]);
        });

        $this->artisan('emp:sync-chargebacks', ['--date' => now()->subDay()->format('Y-m-d')])
            ->assertExitCode(1);
    }
}
