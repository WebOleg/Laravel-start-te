<?php

namespace Tests\Feature\Console;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\EmpAccount;
use App\Models\Upload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportChargebacksCommandTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Phase 1 — Tests for existing functionality
    // ──────────────────────────────────────────────

    public function test_exports_chargebacks_to_local_disk(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create([
            'upload_id' => $upload->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'iban' => 'NL91ABNA0417164300',
        ]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'amount' => 150.00,
            'currency' => 'EUR',
            'chargeback_reason_code' => 'XT73',
            'chargeback_reason_description' => 'SEPA recall',
            'unique_id' => 'EMG-TESTID001',
            'chargebacked_at' => '2026-02-15 10:30:00',
        ]);

        $this->artisan('chargebacks:export')
            ->expectsOutputToContain('Found 1 chargebacks')
            ->expectsOutputToContain('Exported 1 chargebacks')
            ->assertExitCode(0);

        $files = Storage::disk('local')->files();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('chargebacks_export_', $files[0]);

        $csv = Storage::disk('local')->get($files[0]);
        $this->assertStringContainsString('John Doe', $csv);
        $this->assertStringContainsString('NL91ABNA0417164300', $csv);
        $this->assertStringContainsString('XT73', $csv);
        $this->assertStringContainsString('150', $csv);
        $this->assertStringContainsString('EMG-TESTID001', $csv);
    }

    public function test_returns_warning_when_no_chargebacks_found(): void
    {
        Storage::fake('local');

        // Only create approved (not chargebacked) attempts
        BillingAttempt::factory()->approved()->create();

        $this->artisan('chargebacks:export')
            ->expectsOutputToContain('No chargebacks found')
            ->assertExitCode(1);

        $files = Storage::disk('local')->files();
        $this->assertEmpty($files);
    }

    public function test_filters_by_upload_id(): void
    {
        Storage::fake('local');

        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload1->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'EMG-MATCH',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor->id,
            'unique_id' => 'EMG-NOMATCH',
        ]);

        $this->artisan('chargebacks:export', ['--upload_id' => $upload1->id])
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-MATCH', $csv);
        $this->assertStringNotContainsString('EMG-NOMATCH', $csv);
    }

    public function test_filters_by_reason_code(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'XT73',
            'unique_id' => 'EMG-XT73MATCH',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'XT33',
            'unique_id' => 'EMG-XT33SKIP',
        ]);

        $this->artisan('chargebacks:export', ['--reason-code' => 'XT73'])
            ->expectsOutputToContain('Filtering by reason code: XT73')
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $filename = Storage::disk('local')->files()[0];
        $this->assertStringContainsString('_XT73_', $filename);

        $csv = Storage::disk('local')->get($filename);
        $this->assertStringContainsString('EMG-XT73MATCH', $csv);
        $this->assertStringNotContainsString('EMG-XT33SKIP', $csv);
    }

    public function test_combined_upload_id_and_reason_code_filter(): void
    {
        Storage::fake('local');

        $upload1 = Upload::factory()->create();
        $upload2 = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload1->id]);

        // match both filters
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'XT73',
            'unique_id' => 'EMG-BOTH',
        ]);
        // match upload_id only
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload1->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'XT33',
            'unique_id' => 'EMG-UPLOADONLY',
        ]);
        // match reason_code only
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload2->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => 'XT73',
            'unique_id' => 'EMG-REASONONLY',
        ]);

        $this->artisan('chargebacks:export', [
            '--upload_id' => $upload1->id,
            '--reason-code' => 'XT73',
        ])
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-BOTH', $csv);
        $this->assertStringNotContainsString('EMG-UPLOADONLY', $csv);
        $this->assertStringNotContainsString('EMG-REASONONLY', $csv);
    }

    public function test_csv_contains_correct_headers(): void
    {
        Storage::fake('local');

        BillingAttempt::factory()->chargebacked()->create();

        $this->artisan('chargebacks:export')->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $firstLine = strtok($csv, "\n");

        $this->assertStringContainsString('Name', $firstLine);
        $this->assertStringContainsString('IBAN', $firstLine);
        $this->assertStringContainsString('Chargeback Code', $firstLine);
        $this->assertStringContainsString('Chargeback Reason', $firstLine);
        $this->assertStringContainsString('Unique ID', $firstLine);
        $this->assertStringContainsString('Amount', $firstLine);
        $this->assertStringContainsString('Currency', $firstLine);
        $this->assertStringContainsString('Chargebacked At', $firstLine);
    }

    public function test_handles_missing_debtor_gracefully(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        $attempt = BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        // Soft-delete the debtor so the eager-loaded relationship returns null
        // (forceDelete would cascade-delete the billing attempt due to FK constraint)
        $debtor->delete();

        $this->artisan('chargebacks:export')->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $lines = explode("\n", trim($csv));
        $dataLine = $lines[1];

        $this->assertStringContainsString('N/A', $dataLine);
    }

    public function test_handles_null_fields_gracefully(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargeback_reason_code' => null,
            'chargeback_reason_description' => null,
            'unique_id' => null,
            'chargebacked_at' => null,
        ]);

        $this->artisan('chargebacks:export')->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $lines = explode("\n", trim($csv));
        $dataLine = $lines[1];

        // Name and IBAN should be present (debtor exists), but code/reason/unique_id/date are N/A
        $parsed = str_getcsv($dataLine);
        $this->assertEquals('N/A', $parsed[2]); // Chargeback Code
        $this->assertEquals('N/A', $parsed[3]); // Chargeback Reason
        $this->assertEquals('N/A', $parsed[4]); // Unique ID
        $this->assertEquals('N/A', $parsed[7]); // Chargebacked At
    }

    public function test_s3_disk_option(): void
    {
        // TestCase already fakes s3 in setUp()
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
        ]);

        $this->artisan('chargebacks:export', ['--disk' => 's3'])
            ->expectsOutputToContain('File saved to S3/MinIO')
            ->assertExitCode(0);

        $files = Storage::disk('s3')->files();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('chargebacks_export_', $files[0]);
    }

    // ──────────────────────────────────────────────
    // Phase 2 — Tests for new filter options
    // ──────────────────────────────────────────────

    public function test_filters_by_from_date(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-10 14:00:00',
            'unique_id' => 'EMG-TOOOLD',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-02-15 09:00:00',
            'unique_id' => 'EMG-AFTER',
        ]);

        $this->artisan('chargebacks:export', ['--from' => '2026-02-01'])
            ->expectsOutputToContain('Filtering from: 2026-02-01')
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-AFTER', $csv);
        $this->assertStringNotContainsString('EMG-TOOOLD', $csv);
    }

    public function test_filters_by_to_date(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-31 23:59:00',
            'unique_id' => 'EMG-INRANGE',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-02-01 00:01:00',
            'unique_id' => 'EMG-OUTRANGE',
        ]);

        $this->artisan('chargebacks:export', ['--to' => '2026-01-31'])
            ->expectsOutputToContain('Filtering to: 2026-01-31')
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-INRANGE', $csv);
        $this->assertStringNotContainsString('EMG-OUTRANGE', $csv);
    }

    public function test_filters_by_date_range(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-14 23:59:00',
            'unique_id' => 'EMG-BEFORE',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-17 12:00:00',
            'unique_id' => 'EMG-WITHIN',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-21 00:01:00',
            'unique_id' => 'EMG-AFTER',
        ]);

        $this->artisan('chargebacks:export', ['--from' => '2026-01-15', '--to' => '2026-01-20'])
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-WITHIN', $csv);
        $this->assertStringNotContainsString('EMG-BEFORE', $csv);
        $this->assertStringNotContainsString('EMG-AFTER', $csv);
    }

    public function test_filters_by_specific_date(): void
    {
        Storage::fake('local');

        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-15 08:30:00',
            'unique_id' => 'EMG-MATCH',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'chargebacked_at' => '2026-01-16 10:00:00',
            'unique_id' => 'EMG-SKIP',
        ]);

        $this->artisan('chargebacks:export', ['--date' => '2026-01-15'])
            ->expectsOutputToContain('Filtering by date: 2026-01-15')
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-MATCH', $csv);
        $this->assertStringNotContainsString('EMG-SKIP', $csv);
    }

    public function test_date_and_from_to_are_mutually_exclusive(): void
    {
        $this->artisan('chargebacks:export', [
            '--date' => '2026-01-15',
            '--from' => '2026-01-01',
        ])
            ->expectsOutputToContain('Cannot use --date together with --from or --to')
            ->assertExitCode(1);
    }

    public function test_invalid_date_format_shows_error(): void
    {
        $this->artisan('chargebacks:export', ['--from' => 'not-a-date'])
            ->expectsOutputToContain('Invalid --from date format')
            ->assertExitCode(1);

        $this->artisan('chargebacks:export', ['--to' => '15/01/2026'])
            ->expectsOutputToContain('Invalid --to date format')
            ->assertExitCode(1);

        $this->artisan('chargebacks:export', ['--date' => 'yesterday'])
            ->expectsOutputToContain('Invalid --date format')
            ->assertExitCode(1);
    }

    public function test_filters_by_emp_account_id(): void
    {
        Storage::fake('local');

        $empAccount1 = EmpAccount::factory()->create();
        $empAccount2 = EmpAccount::factory()->create();
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $empAccount1->id,
            'unique_id' => 'EMG-EMP1',
        ]);
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $empAccount2->id,
            'unique_id' => 'EMG-EMP2',
        ]);

        $this->artisan('chargebacks:export', ['--emp-account-id' => $empAccount1->id])
            ->expectsOutputToContain('Filtering by EMP account ID: ' . $empAccount1->id)
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-EMP1', $csv);
        $this->assertStringNotContainsString('EMG-EMP2', $csv);
    }

    public function test_all_filters_combined(): void
    {
        Storage::fake('local');

        $empAccount = EmpAccount::factory()->create();
        $upload = Upload::factory()->create();
        $debtor = Debtor::factory()->create(['upload_id' => $upload->id]);

        // This one matches all filters
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $empAccount->id,
            'chargeback_reason_code' => 'XT73',
            'chargebacked_at' => '2026-02-10 12:00:00',
            'unique_id' => 'EMG-ALLFILTERS',
        ]);

        // Wrong reason code
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $empAccount->id,
            'chargeback_reason_code' => 'XT33',
            'chargebacked_at' => '2026-02-10 12:00:00',
            'unique_id' => 'EMG-WRONGCODE',
        ]);

        // Wrong date
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => $empAccount->id,
            'chargeback_reason_code' => 'XT73',
            'chargebacked_at' => '2026-03-15 12:00:00',
            'unique_id' => 'EMG-WRONGDATE',
        ]);

        // Wrong emp account
        BillingAttempt::factory()->chargebacked()->create([
            'upload_id' => $upload->id,
            'debtor_id' => $debtor->id,
            'emp_account_id' => EmpAccount::factory()->create()->id,
            'chargeback_reason_code' => 'XT73',
            'chargebacked_at' => '2026-02-10 12:00:00',
            'unique_id' => 'EMG-WRONGEMP',
        ]);

        $this->artisan('chargebacks:export', [
            '--upload_id' => $upload->id,
            '--reason-code' => 'XT73',
            '--from' => '2026-02-01',
            '--to' => '2026-02-28',
            '--emp-account-id' => $empAccount->id,
        ])
            ->expectsOutputToContain('Found 1 chargebacks')
            ->assertExitCode(0);

        $csv = Storage::disk('local')->get(Storage::disk('local')->files()[0]);
        $this->assertStringContainsString('EMG-ALLFILTERS', $csv);
        $this->assertStringNotContainsString('EMG-WRONGCODE', $csv);
        $this->assertStringNotContainsString('EMG-WRONGDATE', $csv);
        $this->assertStringNotContainsString('EMG-WRONGEMP', $csv);
    }
}
