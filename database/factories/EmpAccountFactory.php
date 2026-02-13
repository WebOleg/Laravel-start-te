<?php

namespace Database\Factories;

use App\Models\EmpAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmpAccount>
 */
class EmpAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'slug' => fake()->unique()->regexify('[a-z0-9\-]{1,20}'),
            'endpoint' => fake()->url(),
            'username' => fake()->userName(),
            'password' => fake()->password(12),
            'terminal_token' => fake()->shuffleString('abcdef0123456789abcdef0123456789'),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
            'notes' => fake()->sentence(),
            'monthly_cap' => fake()->numberBetween(300000, 700000),
        ];
    }

    /**
     * Indicate that the account is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
