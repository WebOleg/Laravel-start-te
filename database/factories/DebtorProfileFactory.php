<?php

namespace Database\Factories;

use App\Models\DebtorProfile;
use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DebtorProfile>
 */
class DebtorProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = DebtorProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'iban_hash' => 'hash_' . $this->faker->unique()->iban('DE'),
            'iban_masked' => $this->faker->iban('DE'),
            'billing_model' => DebtorProfile::MODEL_LEGACY,
            'is_active' => true,
            'currency' => 'EUR',
            'billing_amount' => $this->faker->randomFloat(2, 10, 500),
            'next_bill_at' => now()->addMonth(),
            'last_billed_at' => now()->subMonth(),
            'last_success_at' => now()->subMonth(),
            'lifetime_charged_amount' => 0,
        ];
    }
}
