<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->city();

        return [
            'code' => strtoupper(fake()->unique()->bothify('WH-???-##')),
            'name' => $city.' Distribution Centre',
            'address_line' => fake()->streetAddress(),
            'city' => $city,
            'country_code' => fake()->countryCode(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
