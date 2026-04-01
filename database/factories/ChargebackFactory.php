<?php

/**
 * Factory for Chargeback model.
 */

namespace Database\Factories;

use App\Models\BillingAttempt;
use App\Models\Chargeback;
use App\Models\Debtor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChargebackFactory extends Factory
{
    protected $model = Chargeback::class;

    public function definition(): array
    {
        return [
            'billing_attempt_id'             => BillingAttempt::factory(),
            'debtor_id'                      => Debtor::factory(),
            'original_transaction_unique_id' => $this->faker->uuid(),
            'type'                           => Chargeback::TYPE_FIRST_CHARGEBACK,
            'reason_code'                    => $this->faker->randomElement(['AC01', 'MD01', 'AM04', 'MS02', 'AG01']),
            'reason_description'             => $this->faker->sentence(),
            'chargeback_amount'              => $this->faker->randomFloat(2, 5, 500),
            'chargeback_currency'            => 'EUR',
            'post_date'                      => null,
            'import_date'                    => now()->toDateString(),
            'source'                         => Chargeback::SOURCE_API_SYNC,
            'api_response'                   => null,
        ];
    }
}
