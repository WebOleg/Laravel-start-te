<?php

/**
 * Factory for generating test Upload records.
 */

namespace Database\Factories;

use App\Models\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UploadFactory extends Factory
{
    protected $model = Upload::class;

    public function definition(): array
    {
        $totalRecords = fake()->numberBetween(10, 500);
        $processedRecords = fake()->numberBetween(0, $totalRecords);
        $failedRecords = fake()->numberBetween(0, $totalRecords - $processedRecords);

        return [
            'filename' => Str::uuid() . '.csv',
            'original_filename' => fake()->word() . '_' . fake()->date('Y_m') . '.csv',
            'file_path' => '/storage/uploads/' . Str::uuid() . '.csv',
            'file_size' => fake()->numberBetween(10000, 500000),
            'mime_type' => 'text/csv',
            'status' => fake()->randomElement([
                Upload::STATUS_PENDING,
                Upload::STATUS_PROCESSING,
                Upload::STATUS_COMPLETED,
                Upload::STATUS_FAILED,
            ]),
            'total_records' => $totalRecords,
            'processed_records' => $processedRecords,
            'failed_records' => $failedRecords,
            'uploaded_by' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Upload::STATUS_PENDING,
            'processed_records' => 0,
            'failed_records' => 0,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Upload::STATUS_COMPLETED,
            'processed_records' => $attributes['total_records'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Upload::STATUS_FAILED,
            'error_message' => 'Processing failed: ' . fake()->sentence(),
        ]);
    }
}
