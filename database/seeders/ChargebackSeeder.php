<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use App\Models\User;

class ChargebackSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get the first user or create a test user
        $user = User::first();
        
        if (!$user) {
            $user = User::create([
                'name' => 'Test Admin',
                'email' => 'admin@test.com',
                'password' => bcrypt('password'),
            ]);
            $this->command->info('Created test user: admin@test.com');
        }

        // Get or create an upload
        $upload = Upload::firstOrCreate(
            ['filename' => 'chargeback_test_upload.csv'],
            [
                'original_filename' => 'chargeback_test_upload.csv',
                'file_path' => 'uploads/chargeback_test_upload.csv',
                'file_size' => 1024,
                'mime_type' => 'text/csv',
                'status' => Upload::STATUS_COMPLETED,
                'total_records' => 6,
                'processed_records' => 6,
                'failed_records' => 0,
                'uploaded_by' => $user->id,
            ]
        );

        $chargebacks = [
            [
                'unique_id' => '18e0cb1ae82f0946da1f87a52f4d5557',
                'amount' => 150.00,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'iban' => 'DE89370400440532013000',
                'bic' => 'COBADEFFXXX',
            ],
            [
                'unique_id' => 'e3eb7d3e9af9c0f266832c2811e5f6c4',
                'amount' => 200.50,
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'iban' => 'FR1420041010050500013M02606',
                'bic' => 'BNPAFRPPXXX',
            ],
            [
                'unique_id' => '30bf2b6dd13a42ca279c4bbb7d1feb82',
                'amount' => 300.00,
                'first_name' => 'Bob',
                'last_name' => 'Johnson',
                'iban' => 'GB82WEST12345698765432',
                'bic' => 'WESTGB21XXX',
            ],
            [
                'unique_id' => '508657fce24a2d9c6615d37a50254c8f',
                'amount' => 175.75,
                'first_name' => 'Alice',
                'last_name' => 'Williams',
                'iban' => 'NL91ABNA0417164300',
                'bic' => 'ABNANL2AXXX',
            ],
            [
                'unique_id' => 'a6abd22fe9e03846f9c2c013f2b587d9',
                'amount' => 250.00,
                'first_name' => 'Charlie',
                'last_name' => 'Brown',
                'iban' => 'BE68539007547034',
                'bic' => 'GKCCBEBB',
            ],
            [
                'unique_id' => '5e988a80bd137b3dac3abc1f7b5d8da0',
                'amount' => 125.25,
                'first_name' => 'Diana',
                'last_name' => 'Martinez',
                'iban' => 'ES9121000418450200051332',
                'bic' => 'CAIXESBBXXX',
            ],
        ];

        foreach ($chargebacks as $index => $chargebackData) {
            // Create debtor
            $debtor = Debtor::create([
                'upload_id' => $upload->id,
                'iban' => $chargebackData['iban'],
                'iban_hash' => hash('sha256', $chargebackData['iban']),
                'iban_valid' => true,
                'first_name' => $chargebackData['first_name'],
                'last_name' => $chargebackData['last_name'],
                'email' => strtolower($chargebackData['first_name']) . '.' . strtolower($chargebackData['last_name']) . '@example.com',
                'amount' => $chargebackData['amount'],
                'currency' => 'EUR',
                'status' => Debtor::STATUS_RECOVERED,
                'validation_status' => Debtor::VALIDATION_VALID,
                'vop_status' => Debtor::VOP_VERIFIED,
                'vop_match' => true,
                'bic' => $chargebackData['bic'],
                'bank_name' => 'Test Bank ' . ($index + 1),
                'country' => strtoupper(substr($chargebackData['iban'], 0, 2)),
            ]);

            // Create billing attempt with chargeback
            BillingAttempt::create([
                'debtor_id' => $debtor->id,
                'upload_id' => $upload->id,
                'transaction_id' => 'txn_' . uniqid(),
                'unique_id' => $chargebackData['unique_id'],
                'amount' => $chargebackData['amount'],
                'currency' => 'EUR',
                'status' => BillingAttempt::STATUS_CHARGEBACKED,
                'attempt_number' => 1,
                'bic' => $chargebackData['bic'],
                'request_payload' => [
                    'iban' => $chargebackData['iban'],
                    'bic' => $chargebackData['bic'],
                    'amount' => $chargebackData['amount'],
                    'currency' => 'EUR',
                    'first_name' => $chargebackData['first_name'],
                    'last_name' => $chargebackData['last_name'],
                ],
                'response_payload' => [
                    'status' => 'approved',
                    'unique_id' => $chargebackData['unique_id'],
                ],
                'processed_at' => now()->subDays(rand(30, 90)),
                'emp_created_at' => now()->subDays(rand(30, 90)),
                'chargebacked_at' => now()->subDays(rand(1, 10)),
                'chargeback_reason_code' => null, // Will be populated by the command
                'chargeback_reason_description' => null,
                'chargeback_reason_technical_message' => null,
            ]);
        }

        $this->command->info('Created 6 billing attempts with chargebacks for testing.');
    }
}
