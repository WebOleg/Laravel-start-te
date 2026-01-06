<?php

namespace Database\Seeders;

use App\Jobs\ProcessVopJob;
use App\Models\Upload;
use App\Models\Debtor;
use App\Models\User;
use Illuminate\Database\Seeder;

class UploadOnlyTestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
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

        // Create 10 test uploads
        $uploads = [
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 3, 'status' => Upload::STATUS_PROCESSING],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 5, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 10, 'status' => Upload::STATUS_FAILED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 8, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 15, 'status' => Upload::STATUS_PROCESSING],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 20, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 12, 'status' => Upload::STATUS_FAILED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 150, 'status' => Upload::STATUS_COMPLETED],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 4, 'status' => Upload::STATUS_PROCESSING],
            ['filename' => 'new_upload_'.rand(0, 9999).'.csv', 'total' => 3, 'status' => Upload::STATUS_COMPLETED],
        ];

        foreach ($uploads as $uploadData) {
            $upload = Upload::create([
                'filename' => bin2hex(random_bytes(16)) . '.csv',
                'original_filename' => $uploadData['filename'],
                'file_path' => '/storage/uploads/' . bin2hex(random_bytes(16)) . '.csv',
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
                'postcode' => (string) rand(10000, 99999),
                'city' => $cities[array_rand($cities)],
                'country' => $country,
                'amount' => rand(50, 500) + (rand(0, 99) / 100),
                'currency' => 'EUR',
                'validation_status' => Debtor::VALIDATION_VALID,
                'iban_valid' => true,
            ]);
        }
    }

    private function generateRandomString(int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $result;
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
