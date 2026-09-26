<?php

namespace Tests\Feature\Orders;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Notifications\OrderShippedNotification;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    private function stocked(int $onHand, int $priceCents = 1000): Product
    {
        $product = Product::factory()->create(['unit_price_cents' => $priceCents]);
        StockLevel::factory()->for($this->warehouse)->for($product)->quantity($onHand)->create();

        return $product;
    }

    private function payload(array $items, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'items' => $items,
            'ship_to' => [
                'name' => 'Receiving Dock',
                'line1' => '1 Industrial Way',
                'city' => 'Leeds',
                'postal_code' => 'LS1 1AA',
                'country' => 'GB',
            ],
        ], $overrides);
    }

    private function placeOrder(array $items): Order
    {
        return app(OrderService::class)->place($this->payload($items), $this->userWithRole(Role::Sales));
    }

    public function test_sales_can_place_an_order_which_reserves_stock_at_catalogue_prices(): void
    {
        $bolts = $this->stocked(100, priceCents: 45);
        $nuts = $this->stocked(50, priceCents: 12);
        $this->actingAsRole(Role::Sales);

        $response = $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $bolts->id, 'quantity' => 10, 'unit_price_cents' => 1],
            ['product_id' => $nuts->id, 'quantity' => 20],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.subtotal_cents', 10 * 45 + 20 * 12)
            ->assertJsonPath('data.items.0.unit_price_cents', 45)
            ->assertJsonPath('data.number', fn ($number) => str_starts_with($number, 'SO-'));

        $this->assertSame(10, StockLevel::where('product_id', $bolts->id)->value('reserved'));
        $this->assertSame(20, StockLevel::where('product_id', $nuts->id)->value('reserved'));
    }

    public function test_order_number_is_assigned_even_when_model_events_are_faked(): void
    {
        // Regression: numbers used to be set in a model event, which Event::fake() suppresses.
        $product = $this->stocked(10);

        $order = Event::fakeFor(fn () => $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]));

        $this->assertSame(Order::numberFor($order->id), $order->fresh()->number);
    }

    public function test_order_is_rejected_and_nothing_persists_when_stock_is_short(): void
    {
        $bolts = $this->stocked(100);
        $nuts = $this->stocked(5);
        $this->actingAsRole(Role::Sales);

        $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $bolts->id, 'quantity' => 10],
            ['product_id' => $nuts->id, 'quantity' => 6],
        ]))->assertConflict()
            ->assertJsonPath('shortages.0.product_id', $nuts->id)
            ->assertJsonPath('shortages.0.available', 5);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, StockLevel::where('product_id', $bolts->id)->value('reserved'));
    }

    public function test_order_validation(): void
    {
        $product = $this->stocked(10);
        $inactive = Product::factory()->inactive()->create();
        $this->actingAsRole(Role::Sales);

        $this->postJson('/api/v1/orders', $this->payload([
            ['product_id' => $product->id, 'quantity' => 1],
            ['product_id' => $product->id, 'quantity' => 0],
            ['product_id' => $inactive->id, 'quantity' => 1],
        ], ['warehouse_id' => Warehouse::factory()->inactive()->create()->id, 'ship_to' => ['name' => 'x']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'warehouse_id', 'items.1.product_id', 'items.1.quantity', 'items.2.product_id',
                'ship_to.line1', 'ship_to.city', 'ship_to.postal_code', 'ship_to.country',
            ]);
    }

    public function test_manager_ships_an_order_and_the_customer_is_notified(): void
    {
        Notification::fake();
        $product = $this->stocked(30);
        $order = $this->placeOrder([['product_id' => $product->id, 'quantity' => 8]]);
        $this->actingAsRole(Role::WarehouseManager, [$this->warehouse]);

        $this->postJson("/api/v1/orders/{$order->id}/ship", ['tracking_number' => '1Z999AA10123456784'])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.tracking_number', '1Z999AA10123456784');

        $level = StockLevel::where('product_id', $product->id)->sole();
        $this->assertSame([22, 0], [$level->on_hand, $level->reserved]);

        Notification::assertSentTo($this->customer, OrderShippedNotification::class,
            fn (OrderShippedNotification $n) => $n->order->is($order));
    }

    public function test_cancelling_an_order_releases_its_reservation(): void
    {
        $product = $this->stocked(30);
        $order = $this->placeOrder([['product_id' => $product->id, 'quantity' => 8]]);
        $this->actingAsRole(Role::Sales);

        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'Customer changed their mind'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Customer changed their mind');

        $level = StockLevel::where('product_id', $product->id)->sole();
        $this->assertSame([30, 0], [$level->on_hand, $level->reserved]);
    }

    public function test_shipped_orders_cannot_be_cancelled_and_cancelled_orders_cannot_ship(): void
    {
        Notification::fake();
        $product = $this->stocked(30);
        $shipped = $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $cancelled = $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $admin = $this->actingAsRole(Role::Admin);
        app(OrderService::class)->ship($shipped, $admin, 'TRACK-1');
        app(OrderService::class)->cancel($cancelled, $admin, 'Duplicate');

        $this->postJson("/api/v1/orders/{$shipped->id}/cancel", ['reason' => 'Too late'])
            ->assertConflict()
            ->assertJsonPath('current_status', 'shipped');
        $this->postJson("/api/v1/orders/{$cancelled->id}/ship", ['tracking_number' => 'X'])
            ->assertConflict();
        $this->postJson("/api/v1/orders/{$shipped->id}/ship", ['tracking_number' => 'AGAIN'])
            ->assertConflict();

        $this->assertSame(29, StockLevel::where('product_id', $product->id)->value('on_hand'));
    }

    public function test_role_and_warehouse_rules_for_order_actions(): void
    {
        $product = $this->stocked(30);
        $order = $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $otherWarehouse = Warehouse::factory()->create();

        // Sales can cancel but not ship.
        $this->actingAsRole(Role::Sales);
        $this->postJson("/api/v1/orders/{$order->id}/ship", ['tracking_number' => 'X'])->assertForbidden();

        // A manager of another warehouse can neither see nor ship the order.
        $this->actingAsRole(Role::WarehouseManager, [$otherWarehouse]);
        $this->getJson("/api/v1/orders/{$order->id}")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/ship", ['tracking_number' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('meta.total', 0);

        // Managers fulfil orders but cannot place or cancel them.
        $this->actingAsRole(Role::WarehouseManager, [$this->warehouse]);
        $this->postJson('/api/v1/orders', $this->payload([['product_id' => $product->id, 'quantity' => 1]]))->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_orders_can_be_filtered_and_are_listed_without_n_plus_one_queries(): void
    {
        $product = $this->stocked(100);
        $first = $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        $this->placeOrder([['product_id' => $product->id, 'quantity' => 1]]);
        app(OrderService::class)->cancel($first, $this->userWithRole(Role::Admin), 'Test');
        $this->actingAsRole(Role::Sales);

        // Strict mode (enabled outside production) throws on lazy loading,
        // so a passing request proves relations are eager loaded.
        $this->getJson('/api/v1/orders?status=confirmed')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.items_count', 1)
            ->assertJsonPath('data.0.customer.id', $this->customer->id);

        $this->getJson('/api/v1/orders?number='.strtolower($first->number))
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'cancelled');
    }
}
