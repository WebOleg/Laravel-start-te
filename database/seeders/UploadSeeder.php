<?php

/**
 * Seeder for creating test uploads with related debtors, VOP logs, and billing attempts.
 */

namespace Database\Seeders;

use App\Models\Upload;
use App\Models\Debtor;
use App\Models\VopLog;
use App\Models\BillingAttempt;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UploadSeeder extends Seeder
{
    public function run(): void
    {
        // Create test user if not exists
        $user = User::firstOrCreate(
            ['email' => 'admin@tether.test'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );

        // Create 3 test uploads
        $uploads = [
            ['filename' => 'december_debtors.csv', 'total' => 10, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'november_batch.csv', 'total' => 5, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'new_upload.csv', 'total' => 3, 'status' => Upload::STATUS_PROCESSING],
        ];

        foreach ($uploads as $uploadData) {
            $upload = Upload::create([
                'filename' => Str::uuid() . '.csv',
                'original_filename' => $uploadData['filename'],
                'file_path' => '/storage/uploads/' . Str::uuid() . '.csv',
                'file_size' => rand(10000, 500000),
                'mime_type' => 'text/csv',
                'status' => $uploadData['status'],
                'total_records' => $uploadData['total'],
                'processed_records' => $uploadData['status'] === Upload::STATUS_COMPLETED ? $uploadData['total'] : rand(0, $uploadData['total']),
                'failed_records' => rand(0, 2),
                'uploaded_by' => $user->id,
            ]);

            $this->createDebtors($upload, $uploadData['total']);
        }
    }

    private function createDebtors(Upload $upload, int $count): void
    {
        $firstNames = ['Hans', 'Anna', 'Peter', 'Maria', 'Klaus', 'Eva', 'Thomas', 'Sophie', 'Michael', 'Laura'];
        $lastNames = ['Mueller', 'Schmidt', 'Weber', 'Fischer', 'Wagner', 'Becker', 'Hoffmann', 'Schulz', 'Koch', 'Richter'];
        $cities = ['Berlin', 'Munich', 'Hamburg', 'Frankfurt', 'Cologne', 'Stuttgart', 'Dusseldorf', 'Vienna', 'Zurich', 'Amsterdam'];
        $countries = ['DE', 'DE', 'DE', 'DE', 'AT', 'CH', 'NL'];
        $statuses = [Debtor::STATUS_PENDING, Debtor::STATUS_PROCESSING, Debtor::STATUS_RECOVERED, Debtor::STATUS_FAILED];
        $riskClasses = [Debtor::RISK_LOW, Debtor::RISK_MEDIUM, Debtor::RISK_HIGH];

        for ($i = 0; $i < $count; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $country = $countries[array_rand($countries)];

            $debtor = Debtor::create([
                'upload_id' => $upload->id,
                'iban' => $this->generateIban($country),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => strtolower($firstName . '.' . $lastName . rand(1, 99) . '@example.com'),
                'phone' => '+49' . rand(100, 999) . rand(1000000, 9999999),
                'address' => 'Street ' . rand(1, 200),
                'zip_code' => (string) rand(10000, 99999),
                'city' => $cities[array_rand($cities)],
                'country' => $country,
                'amount' => rand(50, 500) + (rand(0, 99) / 100),
                'currency' => 'EUR',
                'status' => $statuses[array_rand($statuses)],
                'risk_class' => $riskClasses[array_rand($riskClasses)],
                'external_reference' => 'ORDER-' . strtoupper(Str::random(8)),
            ]);

            $this->createVopLog($debtor, $upload);
            $this->createBillingAttempts($debtor, $upload);
        }
    }

    private function createVopLog(Debtor $debtor, Upload $upload): void
    {
        $results = [
            VopLog::RESULT_VERIFIED => ['score' => rand(80, 100), 'valid' => true, 'bank' => true],
            VopLog::RESULT_LIKELY_VERIFIED => ['score' => rand(60, 79), 'valid' => true, 'bank' => true],
            VopLog::RESULT_INCONCLUSIVE => ['score' => rand(40, 59), 'valid' => true, 'bank' => false],
            VopLog::RESULT_MISMATCH => ['score' => rand(20, 39), 'valid' => false, 'bank' => true],
            VopLog::RESULT_REJECTED => ['score' => rand(0, 19), 'valid' => false, 'bank' => false],
        ];

        $result = array_rand($results);
        $data = $results[$result];
        $banks = ['Deutsche Bank', 'Commerzbank', 'Sparkasse', 'Volksbank', 'ING', 'N26'];

        VopLog::create([
            'debtor_id' => $debtor->id,
            'upload_id' => $upload->id,
            'iban_masked' => $debtor->iban_masked,
            'iban_valid' => $data['valid'],
            'bank_identified' => $data['bank'],
            'bank_name' => $data['bank'] ? $banks[array_rand($banks)] : null,
            'bic' => $data['bank'] ? 'DEUTDE' . strtoupper(Str::random(2)) : null,
            'country' => $debtor->country,
            'vop_score' => $data['score'],
            'result' => $result,
        ]);
    }

    private function createBillingAttempts(Debtor $debtor, Upload $upload): void
    {
        $attemptCount = rand(1, 3);
        $statuses = [BillingAttempt::STATUS_APPROVED, BillingAttempt::STATUS_DECLINED, BillingAttempt::STATUS_ERROR];
        $errors = [
            'AC04' => 'Account closed',
            'AC06' => 'Account blocked',
            'AG01' => 'Transaction forbidden',
            'AM04' => 'Insufficient funds',
            'MD01' => 'No mandate',
        ];

        for ($i = 1; $i <= $attemptCount; $i++) {
            $isLast = ($i === $attemptCount);
            $status = $isLast ? $statuses[array_rand($statuses)] : BillingAttempt::STATUS_DECLINED;
            $hasError = in_array($status, [BillingAttempt::STATUS_DECLINED, BillingAttempt::STATUS_ERROR]);
            $errorCode = $hasError ? array_rand($errors) : null;

            BillingAttempt::create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'transaction_id' => 'TXN-' . strtoupper(Str::random(12)),
                'unique_id' => 'EMG-' . strtoupper(Str::random(10)),
                'amount' => $debtor->amount,
                'currency' => $debtor->currency,
                'status' => $status,
                'attempt_number' => $i,
                'error_code' => $errorCode,
                'error_message' => $errorCode ? $errors[$errorCode] : null,
                'processed_at' => now()->subDays(rand(0, 30)),
            ]);
        }
    }

    private function generateIban(string $country): string
    {
        $length = $country === 'DE' ? 18 : 16;
        $numbers = '';
        for ($i = 0; $i < $length; $i++) {
            $numbers .= rand(0, 9);
        }
        return $country . rand(10, 99) . $numbers;
    }
}
