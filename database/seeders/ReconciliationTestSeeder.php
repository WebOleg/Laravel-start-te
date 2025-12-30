<?php

namespace Database\Seeders;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ReconciliationTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates test data for reconciliation testing:
     * Uploads -> Debtors -> VOP Logs -> Billing Attempts
     *
     * Reconciliation Eligibility Criteria:
     * - Status: pending
     * - Has unique_id (was sent to EMP)
     * - Age: > 2 hours (RECONCILIATION_MIN_AGE_HOURS)
     * - Attempts: < 10 (RECONCILIATION_MAX_ATTEMPTS)
     *
     * All debtors are created with valid IBANs and pass validation.
     */
    public function run(): void
    {
        // Valid test IBANs (with proper checksums)
        $validIbans = [
            'DE89370400440532013000', // German IBAN
            'FR1420041010050500013M02606', // French IBAN
            'ES9121000418450200051332', // Spanish IBAN
        ];

        // Create test upload
        $upload = Upload::factory()->create([
            'status' => Upload::STATUS_COMPLETED,
            'total_records' => 3,
            'processed_records' => 3,
            'failed_records' => 0,
        ]);

        // Create 3 eligible billing attempts for reconciliation
        foreach ($validIbans as $index => $iban) {
            // Create debtor with valid IBAN
            $debtor = Debtor::create([
                'upload_id' => $upload->id,
                'iban' => $iban,
                'iban_hash' => hash('sha256', $iban),
                'iban_valid' => true,
                'first_name' => 'Test',
                'last_name' => 'Debtor',
                'email' => 'debtor' . ($index + 1) . '@test.com',
                'amount' => 100.00 + ($index * 50),
                'currency' => 'EUR',
                'status' => Debtor::STATUS_PENDING,
                'validation_status' => Debtor::VALIDATION_VALID,
                'validated_at' => now(),
                'risk_class' => Debtor::RISK_LOW,
                'country' => substr($iban, 0, 2),
            ]);

            // Create VOP log (verified status)
            VopLog::create([
                'upload_id' => $upload->id,
                'debtor_id' => $debtor->id,
                'iban_masked' => substr($iban, 0, 2) . '**' . substr($iban, -4),
                'iban_valid' => true,
                'bank_identified' => true,
                'bank_name' => 'Test Bank',
                'bic' => 'TESTBIC',
                'country' => substr($iban, 0, 2),
                'vop_score' => 85,
                'result' => VopLog::RESULT_VERIFIED,
                'meta' => ['verified' => true],
            ]);

            // Create billing attempt meeting reconciliation criteria
            BillingAttempt::create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'transaction_id' => 'TXN-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)),
                'unique_id' => 'UNIQUE-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)), // Required for reconciliation
                'amount' => 100.00 + ($index * 50),
                'currency' => 'EUR',
                'status' => BillingAttempt::STATUS_PENDING, // Required status
                'attempt_number' => 1,
                'reconciliation_attempts' => $index, // 0, 1, 2 - all less than RECONCILIATION_MAX_ATTEMPTS (10)
                'created_at' => now()->subHours(3 + $index), // Created 3+ hours ago (> 2 hours requirement)
                'updated_at' => now()->subHours(3 + $index),
                'processed_at' => now()->subHours(3 + $index),
                'request_payload' => [
                    'iban' => $iban,
                    'amount' => 100.00 + ($index * 50),
                    'currency' => 'EUR',
                ],
                'response_payload' => [
                    'status' => 'pending',
                    'unique_id' => 'UNIQUE-' . uniqid(),
                ],
                'meta' => ['test' => true, 'reconciliation_eligible' => true],
            ]);
        }
    }
}
