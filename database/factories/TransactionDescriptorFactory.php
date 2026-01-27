<?php

namespace Database\Factories;

use App\Models\TransactionDescriptor;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionDescriptorFactory extends Factory
{
    protected $model = TransactionDescriptor::class;

    public function definition(): array
    {
        return [
            'year'               => $this->faker->unique()->numberBetween(2020, 2030),
            'month'              => $this->faker->numberBetween(1, 12),
            'descriptor_name'    => substr($this->faker->company(), 0, 25), // max 25 chars
            'descriptor_city'    => substr($this->faker->city(), 0, 13),   // max 13 chars
            'descriptor_country' => $this->faker->countryISOAlpha3(),      // 3 chars
            'is_default'         => false,
        ];
    }
}
