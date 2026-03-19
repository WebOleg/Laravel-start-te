<?php

namespace Database\Factories;

use App\Models\BavBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BavBatch>
 */
class BavBatchFactory extends Factory
{
    protected $model = BavBatch::class;

    public function definition(): array
    {
        $total = fake()->numberBetween(100, 20000);
        $hasLimit = fake()->boolean(40);
        $recordLimit = $hasLimit ? fake()->numberBetween(50, $total) : null;

        return [
            'user_id'           => User::factory(),
            'original_filename' => fake()->bothify('batch_????_##_') . fake()->date('Ymd') . '.csv',
            'file_path'         => 'bav-batches/input/' . Str::uuid() . '.csv',
            'results_path'      => null,
            'status'            => BavBatch::STATUS_PENDING,
            'total_records'     => $total,
            'record_limit'      => $recordLimit,
            'processed_records' => 0,
            'success_count'     => 0,
            'failed_count'      => 0,
            'credits_used'      => 0,
            'batch_id'          => null,
            'column_mapping'    => [
                'iban_col'       => 0,
                'first_name_col' => fake()->boolean(70) ? 1 : null,
                'last_name_col'  => fake()->boolean(70) ? 2 : null,
                'bic_col'        => fake()->boolean(30) ? 3 : null,
                'has_headers'    => fake()->boolean(80),
            ],
            'meta'         => null,
            'started_at'   => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'            => BavBatch::STATUS_PENDING,
            'processed_records' => 0,
            'success_count'     => 0,
            'failed_count'      => 0,
            'credits_used'      => 0,
            'batch_id'          => null,
            'results_path'      => null,
            'started_at'        => null,
            'completed_at'      => null,
        ]);
    }

    public function processing(): static
    {
        return $this->state(function (array $attributes) {
            $effectiveLimit = $attributes['record_limit'] ?? $attributes['total_records'];
            $processed = fake()->numberBetween(1, max(1, $effectiveLimit - 1));
            $success   = (int) ($processed * fake()->randomFloat(2, 0.7, 1.0));
            $failed    = $processed - $success;

            return [
                'status'            => BavBatch::STATUS_PROCESSING,
                'batch_id'          => Str::uuid(),
                'processed_records' => $processed,
                'success_count'     => $success,
                'failed_count'      => $failed,
                'credits_used'      => $processed,
                'started_at'        => fake()->dateTimeBetween('-7 days', '-1 hour'),
                'completed_at'      => null,
            ];
        });
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $effectiveLimit = $attributes['record_limit'] ?? $attributes['total_records'];
            $processed      = $effectiveLimit;
            $success        = (int) ($processed * fake()->randomFloat(2, 0.7, 1.0));
            $failed         = $processed - $success;
            $startedAt      = fake()->dateTimeBetween('-30 days', '-2 hours');

            return [
                'status'            => BavBatch::STATUS_COMPLETED,
                'batch_id'          => Str::uuid(),
                'results_path'      => 'bav-batches/results/' . Str::uuid() . '_results.csv',
                'processed_records' => $processed,
                'success_count'     => $success,
                'failed_count'      => $failed,
                'credits_used'      => $processed,
                'started_at'        => $startedAt,
                'completed_at'      => fake()->dateTimeBetween($startedAt, 'now'),
            ];
        });
    }

    public function failed(): static
    {
        return $this->state(function (array $attributes) {
            $effectiveLimit = $attributes['record_limit'] ?? $attributes['total_records'];
            $processed      = fake()->numberBetween(0, (int) ($effectiveLimit * 0.5));
            $success        = (int) ($processed * fake()->randomFloat(2, 0.5, 1.0));
            $failed         = $processed - $success;
            $startedAt      = fake()->dateTimeBetween('-30 days', '-2 hours');
            $errors         = [
                'Storage connection failed while writing results.',
                'Unexpected end of CSV file at row ' . fake()->numberBetween(100, 5000) . '.',
                'BAV API rate limit exceeded.',
                'Memory limit reached during batch processing.',
                'Invalid IBAN format detected in bulk records.',
                'Queue worker timed out after 600 seconds.',
            ];

            return [
                'status'            => BavBatch::STATUS_FAILED,
                'batch_id'          => Str::uuid(),
                'processed_records' => $processed,
                'success_count'     => $success,
                'failed_count'      => $failed,
                'credits_used'      => $processed,
                'started_at'        => $startedAt,
                'completed_at'      => fake()->dateTimeBetween($startedAt, 'now'),
                'meta'              => ['error' => fake()->randomElement($errors)],
            ];
        });
    }
}
