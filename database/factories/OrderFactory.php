<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates orders without touching stock. Use OrderService to place real orders.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order) {
            $order->number ??= Order::numberFor($order->id);
            $order->save();
        });
    }

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => OrderStatus::Confirmed,
            'subtotal_cents' => fake()->numberBetween(1_000, 250_000),
            'ship_to_name' => fake()->name(),
            'ship_to_line1' => fake()->streetAddress(),
            'ship_to_city' => fake()->city(),
            'ship_to_postal_code' => fake()->postcode(),
            'ship_to_country' => fake()->countryCode(),
        ];
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Shipped,
            'shipped_at' => now(),
            'tracking_number' => strtoupper(fake()->bothify('1Z###??#########')),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Customer request',
        ]);
    }
}
