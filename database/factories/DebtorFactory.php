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
        $country = $this->faker->randomElement(['DE', 'ES', 'FR', 'NL', 'IT']);
        
        return [
            'upload_id' => Upload::factory(),
            'iban' => $this->generateIban($country),
            'iban_hash' => fn (array $attrs) => hash('sha256', $attrs['iban']),
            'old_iban' => null,
            'bank_name' => $this->faker->company() . ' Bank',
            'bank_code' => $this->faker->numerify('####'),
            'bic' => strtoupper($this->faker->lexify('????????XXX')),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->numerify('6########'),
            'phone_2' => $this->faker->optional(0.3)->numerify('6########'),
            'phone_3' => null,
            'phone_4' => null,
            'primary_phone' => $this->faker->numerify('6########'),
            'national_id' => $this->faker->numerify('########') . $this->faker->randomLetter(),
            'birth_date' => $this->faker->dateTimeBetween('-70 years', '-18 years'),
            'address' => $this->faker->streetAddress(),
            'street' => $this->faker->streetName(),
            'street_number' => $this->faker->buildingNumber(),
            'floor' => $this->faker->optional(0.5)->numberBetween(1, 10),
            'door' => $this->faker->optional(0.5)->randomLetter(),
            'apartment' => null,
            'postcode' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'province' => $this->faker->state(),
            'country' => $country,
            'amount' => $this->faker->randomFloat(2, 50, 5000),
            'currency' => 'EUR',
            'sepa_type' => $this->faker->optional(0.3)->randomElement(['CORE', 'B2B']),
            'status' => Debtor::STATUS_UPLOADED,
            'risk_class' => $this->faker->randomElement(Debtor::RISK_CLASSES),
            'iban_valid' => true,
            'name_matched' => $this->faker->boolean(80),
            'external_reference' => $this->faker->optional(0.5)->uuid(),
            'meta' => null,
        ];
    }
    public function uploaded(): static
    {
        return $this->state(fn () => ['status' => Debtor::STATUS_UPLOADED]);
    }
    public function pending(): static
    {
        return $this->state(fn () => ['status' => Debtor::STATUS_PENDING]);
    }
    public function processing(): static
    {
        return $this->state(fn () => ['status' => Debtor::STATUS_PROCESSING]);
    }
    public function recovered(): static
    {
        return $this->state(fn () => ['status' => Debtor::STATUS_RECOVERED]);
    }
    public function failed(): static
    {
        return $this->state(fn () => ['status' => Debtor::STATUS_FAILED]);
    }
    public function highRisk(): static
    {
        return $this->state(fn () => ['risk_class' => Debtor::RISK_HIGH]);
    }
    public function spanish(): static
    {
        return $this->state(fn () => [
            'country' => 'ES',
            'iban' => $this->generateIban('ES'),
        ]);
    }
    public function german(): static
    {
        return $this->state(fn () => [
            'country' => 'DE',
            'iban' => $this->generateIban('DE'),
        ]);
    }
    private function generateIban(string $country): string
    {
        $lengths = [
            'DE' => 22,
            'ES' => 24,
            'FR' => 27,
            'NL' => 18,
            'IT' => 27,
        ];
        
        $length = $lengths[$country] ?? 22;
        $bban = $this->faker->numerify(str_repeat('#', $length - 4));
        
        return $country . '00' . $bban;
    }
}
