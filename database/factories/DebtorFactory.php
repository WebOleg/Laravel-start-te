<?php

/**
 * Factory for generating test Debtor records.
 */

namespace Database\Factories;

use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtorFactory extends Factory
{
    protected $model = Debtor::class;

    public function definition(): array
    {
        $countries = ['DE', 'AT', 'CH', 'NL'];
        $country = fake()->randomElement($countries);

        return [
            'upload_id' => Upload::factory(),
            'iban' => $this->generateIban($country),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->e164PhoneNumber(),
            'address' => fake()->streetAddress(),
            'zip_code' => fake()->postcode(),
            'city' => fake()->city(),
            'country' => $country,
            'amount' => fake()->randomFloat(2, 50, 500),
            'currency' => 'EUR',
            'status' => fake()->randomElement([
                Debtor::STATUS_PENDING,
                Debtor::STATUS_PROCESSING,
                Debtor::STATUS_RECOVERED,
                Debtor::STATUS_FAILED,
            ]),
            'risk_class' => fake()->randomElement([
                Debtor::RISK_LOW,
                Debtor::RISK_MEDIUM,
                Debtor::RISK_HIGH,
            ]),
            'external_reference' => 'ORDER-' . fake()->unique()->randomNumber(6),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Debtor::STATUS_PENDING,
        ]);
    }

    public function recovered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Debtor::STATUS_RECOVERED,
        ]);
    }

    public function highRisk(): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_class' => Debtor::RISK_HIGH,
        ]);
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
