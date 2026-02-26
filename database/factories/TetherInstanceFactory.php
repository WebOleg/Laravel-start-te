<?php

namespace Database\Factories;

use App\Models\TetherInstance;
use Illuminate\Database\Eloquent\Factories\Factory;

class TetherInstanceFactory extends Factory
{
    protected $model = TetherInstance::class;

    public function definition(): array
    {
        return [
            'name'               => $this->faker->company(),
            'slug'               => $this->faker->unique()->slug(2),
            'acquirer_type'      => TetherInstance::ACQUIRER_EMP,
            'acquirer_account_id' => null,
            'acquirer_config'    => null,
            'proxy_ip'           => null,
            'queue_prefix'       => null,
            'is_active'          => true,
            'status'             => TetherInstance::STATUS_ACTIVE,
            'sort_order'         => $this->faker->numberBetween(1, 100),
            'notes'              => null,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'is_active' => true,
            'status'    => TetherInstance::STATUS_ACTIVE,
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
            'status'    => TetherInstance::STATUS_SUSPENDED,
        ]);
    }
}
