<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->companyEmail(),
            'company_name' => fake()->optional(0.8)->company(),
            'phone' => fake()->optional()->e164PhoneNumber(),
        ];
    }
}
