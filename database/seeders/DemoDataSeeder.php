<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Realistic local data. Stock and orders are created through InventoryService and
 * OrderService, so the ledger is complete and `inventory:reconcile` passes.
 *
 * Demo accounts use DEMO_USER_PASSWORD, or a random password printed once.
 */
class DemoDataSeeder extends Seeder
{
    public function run(InventoryService $inventory, OrderService $orders): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be seeded in production.');
        }

        $password = env('DEMO_USER_PASSWORD') ?: Str::password(16, symbols: false);

        $warehouses = collect([
            ['code' => 'LON-01', 'name' => 'London Distribution Centre', 'city' => 'London', 'country_code' => 'GB'],
            ['code' => 'MAN-01', 'name' => 'Manchester Fulfilment Hub', 'city' => 'Manchester', 'country_code' => 'GB'],
            ['code' => 'RTM-01', 'name' => 'Rotterdam Cross-Dock', 'city' => 'Rotterdam', 'country_code' => 'NL'],
        ])->map(fn (array $attributes) => Warehouse::factory()->create($attributes));

        $admin = $this->user('Alex Morgan', 'admin@example.com', Role::Admin, $password);
        $managers = $warehouses->map(fn (Warehouse $w, int $i) => $this->user(
            "{$w->city} Manager", 'manager.'.Str::lower($w->city).'@example.com', Role::WarehouseManager, $password, [$w->id],
        ));
        $sales = $this->user('Sam Patel', 'sales@example.com', Role::Sales, $password);

        $products = Product::factory()->count(40)->create();
        $customers = Customer::factory()->count(25)->create();

        // Avoid sending low-stock mail while seeding.
        Event::fakeFor(function () use ($warehouses, $products, $inventory, $managers) {
            foreach ($warehouses as $i => $warehouse) {
                foreach ($products->random(30) as $product) {
                    $inventory->receive($warehouse->id, $product->id, random_int(20, 400), $managers[$i], 'Opening stock');
                    StockLevel::where(['warehouse_id' => $warehouse->id, 'product_id' => $product->id])
                        ->update(['reorder_point' => Arr::random([0, 15, 30])]);
                }
            }
        });

        Event::fakeFor(function () use ($warehouses, $customers, $orders, $sales, $managers, $admin) {
            foreach (range(1, 60) as $n) {
                $index = array_rand($warehouses->all());
                $warehouse = $warehouses[$index];
                $productIds = StockLevel::where('warehouse_id', $warehouse->id)
                    ->whereRaw('on_hand - reserved >= 5')
                    ->inRandomOrder()
                    ->limit(random_int(1, 4))
                    ->pluck('product_id');

                if ($productIds->isEmpty()) {
                    continue;
                }

                $customer = $customers->random();
                $order = $orders->place([
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'items' => $productIds->map(fn ($id) => ['product_id' => $id, 'quantity' => random_int(1, 5)])->all(),
                    'ship_to' => [
                        'name' => $customer->company_name ?? $customer->name,
                        'line1' => fake()->streetAddress(),
                        'city' => fake()->city(),
                        'postal_code' => fake()->postcode(),
                        'country' => $warehouse->country_code,
                    ],
                ], $sales);

                match (true) {
                    $n % 3 === 0 => $orders->ship($order, $managers[$index], strtoupper(Str::random(16))),
                    $n % 10 === 0 => $orders->cancel($order, $admin, 'Customer cancelled'),
                    default => null,
                };
            }
        });

        $this->command?->info('Demo users: admin@example.com, sales@example.com, '.$managers->pluck('email')->implode(', '));
        if (! env('DEMO_USER_PASSWORD')) {
            $this->command?->warn("Generated demo password: {$password}");
        }
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    private function user(string $name, string $email, Role $role, string $password, array $warehouseIds = []): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->assignRole($role->value);
        $user->warehouses()->sync($warehouseIds);

        return $user;
    }
}
