<?php

/**
 * Factory for generating test BillingAttempt records.
 */

namespace Database\Factories;

use App\Models\BillingAttempt;
use App\Models\Debtor;
use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BillingAttemptFactory extends Factory
{
    protected $model = BillingAttempt::class;

    public function definition(): array
    {
        $statuses = [
            BillingAttempt::STATUS_PENDING,
            BillingAttempt::STATUS_APPROVED,
            BillingAttempt::STATUS_DECLINED,
            BillingAttempt::STATUS_ERROR,
        ];

        $status = fake()->randomElement($statuses);
        $hasError = in_array($status, [BillingAttempt::STATUS_DECLINED, BillingAttempt::STATUS_ERROR]);

        $errors = [
            'AC04' => 'Account closed',
            'AC06' => 'Account blocked',
            'AG01' => 'Transaction forbidden',
            'AM04' => 'Insufficient funds',
            'MD01' => 'No mandate',
        ];

        $errorCode = $hasError ? fake()->randomElement(array_keys($errors)) : null;

        $bics = ['RABONL2UXXX', 'INGBNL2AXXX', 'ABNANL2AXXX', 'DEUTESBBXXX', 'CABORKA1XXX'];

        return [
            'upload_id' => Upload::factory(),
            'debtor_id' => Debtor::factory(),
            'transaction_id' => 'TXN-' . strtoupper(Str::random(12)),
            'unique_id' => 'EMG-' . strtoupper(Str::random(10)),
            'amount' => fake()->randomFloat(2, 50, 500),
            'currency' => 'EUR',
            'status' => $status,
            'attempt_number' => fake()->numberBetween(1, 3),
            'bic' => fake()->randomElement($bics),
            'error_code' => $errorCode,
            'error_message' => $errorCode ? $errors[$errorCode] : null,
            'processed_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BillingAttempt::STATUS_APPROVED,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BillingAttempt::STATUS_DECLINED,
            'error_code' => 'AM04',
            'error_message' => 'Insufficient funds',
        ]);
    }

    public function chargebacked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BillingAttempt::STATUS_CHARGEBACKED,
            'error_code' => 'AC04',
            'error_message' => 'Account closed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => BillingAttempt::STATUS_PENDING,
            'error_code' => null,
            'error_message' => null,
            'processed_at' => null,
        ]);
    }

    public function withBic(string $bic): static
    {
        return $this->state(fn (array $attributes) => [
            'bic' => $bic,
        ]);
    }
}
