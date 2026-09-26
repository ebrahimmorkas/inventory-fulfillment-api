<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates stock levels directly, bypassing the ledger. Use it for test fixtures;
 * application code goes through InventoryService.
 *
 * @extends Factory<StockLevel>
 */
class StockLevelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'product_id' => Product::factory(),
            'on_hand' => fake()->numberBetween(20, 500),
            'reserved' => 0,
            'reorder_point' => fake()->randomElement([0, 10, 25]),
        ];
    }

    public function quantity(int $onHand, int $reserved = 0): static
    {
        return $this->state(fn () => ['on_hand' => $onHand, 'reserved' => $reserved]);
    }
}
