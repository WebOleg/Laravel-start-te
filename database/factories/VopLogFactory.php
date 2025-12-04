<?php

/**
 * Factory for generating test VopLog records.
 */

namespace Database\Factories;

use App\Models\Debtor;
use App\Models\Upload;
use App\Models\VopLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class VopLogFactory extends Factory
{
    protected $model = VopLog::class;

    public function definition(): array
    {
        $results = [
            VopLog::RESULT_VERIFIED => ['score' => fake()->numberBetween(80, 100), 'valid' => true, 'bank' => true],
            VopLog::RESULT_LIKELY_VERIFIED => ['score' => fake()->numberBetween(60, 79), 'valid' => true, 'bank' => true],
            VopLog::RESULT_INCONCLUSIVE => ['score' => fake()->numberBetween(40, 59), 'valid' => true, 'bank' => false],
            VopLog::RESULT_MISMATCH => ['score' => fake()->numberBetween(20, 39), 'valid' => false, 'bank' => true],
            VopLog::RESULT_REJECTED => ['score' => fake()->numberBetween(0, 19), 'valid' => false, 'bank' => false],
        ];

        $result = fake()->randomElement(array_keys($results));
        $data = $results[$result];
        $banks = ['Deutsche Bank', 'Commerzbank', 'Sparkasse', 'Volksbank', 'ING', 'N26'];

        return [
            'upload_id' => Upload::factory(),
            'debtor_id' => Debtor::factory(),
            'iban_masked' => 'DE89****' . fake()->numerify('####'),
            'iban_valid' => $data['valid'],
            'bank_identified' => $data['bank'],
            'bank_name' => $data['bank'] ? fake()->randomElement($banks) : null,
            'bic' => $data['bank'] ? 'DEUTDE' . strtoupper(fake()->lexify('??')) : null,
            'country' => 'DE',
            'vop_score' => $data['score'],
            'result' => $result,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => VopLog::RESULT_VERIFIED,
            'vop_score' => fake()->numberBetween(80, 100),
            'iban_valid' => true,
            'bank_identified' => true,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => VopLog::RESULT_REJECTED,
            'vop_score' => fake()->numberBetween(0, 19),
            'iban_valid' => false,
            'bank_identified' => false,
        ]);
    }
}
