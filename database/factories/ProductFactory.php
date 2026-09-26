<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    private const ITEMS = [
        'Hex bolt' => ['M6 x 30', 'M8 x 40', 'M10 x 50'],
        'Cable tie' => ['200mm', '300mm', '450mm'],
        'Safety gloves' => ['size M', 'size L', 'size XL'],
        'Pallet wrap' => ['17 micron', '23 micron'],
        'Cat6 patch cable' => ['1m', '3m', '5m'],
        'LED panel light' => ['600x600', '1200x300'],
        'Shipping carton' => ['small', 'medium', 'large'],
        'Barcode label roll' => ['50x25mm', '100x150mm'],
        'Hand pallet truck' => ['2000kg', '2500kg'],
        'Hi-vis vest' => ['size L', 'size XL'],
    ];

    public function definition(): array
    {
        $item = fake()->randomKey(self::ITEMS);
        $variant = fake()->randomElement(self::ITEMS[$item]);

        return [
            'sku' => strtoupper(fake()->unique()->bothify('??-####-??')),
            'name' => "{$item} {$variant}",
            'description' => fake()->optional()->sentence(12),
            'unit_price_cents' => fake()->numberBetween(199, 49_999),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
