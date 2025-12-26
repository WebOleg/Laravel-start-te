<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankReference>
 */
class BankReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_iso'=> $this->faker->countryCode(),
            'bank_code' => $this->faker->bothify('####'),
            'bic'       => strtoupper($this->faker->bothify('????##')),
            'bank_name' => $this->faker->company() . ' Bank',
            'branch'    => $this->faker->streetName(),
            'address'   => $this->faker->streetAddress(),
            'city'      => $this->faker->city(),
            'zip'       => $this->faker->postcode(),
            'sepa_sct'  => $this->faker->boolean(80),
            'sepa_sdd'  => $this->faker->boolean(80),
            'sepa_cor1' => $this->faker->boolean(60),
            'sepa_b2b'  => $this->faker->boolean(60),
            'sepa_scc'  => $this->faker->boolean(40),
        ];
    }
}
