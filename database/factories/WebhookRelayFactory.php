<?php

namespace Database\Factories;

use App\Models\WebhookRelay;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebhookRelayFactory extends Factory
{
    protected $model = WebhookRelay::class;

    public function definition(): array
    {
        return [
            'domain' => 'https://' . $this->faker->domainName(),
            'target' => 'https://' . $this->faker->domainName(),
        ];
    }
}
