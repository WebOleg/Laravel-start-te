<?php

/**
 * Feature tests for tether:stats artisan command.
 */

namespace Tests\Feature\Console;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\EmpAccount;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TetherStatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_exits_success_with_no_transactions(): void
    {
        EmpAccount::factory()->create(['name' => 'Test Account', 'is_active' => true]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026])
            ->assertExitCode(0);
    }

    public function test_command_fails_with_invalid_month(): void
    {
        $this->artisan('tether:stats', ['--month' => 13, '--year' => 2026])
            ->assertExitCode(1);
    }

    public function test_command_fails_with_zero_month(): void
    {
        $this->artisan('tether:stats', ['--month' => 0, '--year' => 2026])
            ->assertExitCode(1);
    }

    public function test_command_displays_account_stats(): void
    {
        $account = EmpAccount::factory()->create(['name' => 'Optivest', 'is_active' => true]);
        $upload   = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor   = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(3)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 100.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('Optivest')
            ->expectsOutputToContain('300.00')
            ->assertExitCode(0);
    }

    public function test_command_calculates_net_after_chargebacks(): void
    {
        $account = EmpAccount::factory()->create(['name' => 'Lunaro', 'is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(10)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 50.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        BillingAttempt::factory()->count(2)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'amount'         => 50.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('Lunaro')
            ->expectsOutputToContain('400.00')
            ->assertExitCode(0);
    }

    public function test_command_shows_warning_when_cb_rate_exceeds_threshold(): void
    {
        $account = EmpAccount::factory()->create(['name' => 'Vinci Payments FR', 'is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 100.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_CHARGEBACKED,
            'amount'         => 100.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026, '--cb-threshold' => 2])
            ->expectsOutputToContain('Vinci Payments FR')
            ->expectsOutputToContain('50%')
            ->assertExitCode(0);
    }

    public function test_command_filters_by_account_slug(): void
    {
        $account1 = EmpAccount::factory()->create(['name' => 'Optivest', 'slug' => 'optivest', 'is_active' => true]);
        $account2 = EmpAccount::factory()->create(['name' => 'Lunaro',   'slug' => 'lunaro',   'is_active' => true]);

        $upload1 = Upload::factory()->create(['emp_account_id' => $account1->id]);
        $upload2 = Upload::factory()->create(['emp_account_id' => $account2->id]);

        $debtor1 = Debtor::factory()->create(['upload_id' => $upload1->id]);
        $debtor2 = Debtor::factory()->create(['upload_id' => $upload2->id]);

        BillingAttempt::factory()->create([
            'upload_id'      => $upload1->id,
            'debtor_id'      => $debtor1->id,
            'emp_account_id' => $account1->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 200.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        BillingAttempt::factory()->create([
            'upload_id'      => $upload2->id,
            'debtor_id'      => $debtor2->id,
            'emp_account_id' => $account2->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 300.00,
            'created_at'     => now()->setMonth(3)->setYear(2026),
        ]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026, '--account' => 'optivest'])
            ->expectsOutputToContain('Optivest')
            ->assertExitCode(0);
    }

    public function test_command_ignores_other_months(): void
    {
        $account = EmpAccount::factory()->create(['name' => 'Danieli Soft', 'is_active' => true]);
        $upload  = Upload::factory()->create(['emp_account_id' => $account->id]);
        $debtor  = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->count(5)->create([
            'upload_id'      => $upload->id,
            'debtor_id'      => $debtor->id,
            'emp_account_id' => $account->id,
            'status'         => BillingAttempt::STATUS_APPROVED,
            'amount'         => 100.00,
            'created_at'     => now()->setMonth(2)->setYear(2026),
        ]);

        $this->artisan('tether:stats', ['--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('No transactions found')
            ->assertExitCode(0);
    }
}
