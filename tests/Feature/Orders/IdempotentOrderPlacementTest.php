<?php

namespace Tests\Feature\Orders;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class IdempotentOrderPlacementTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();
        StockLevel::factory()->for($warehouse)->for($product)->quantity(50)->create();

        $this->payload = [
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'ship_to' => ['name' => 'A', 'line1' => 'B', 'city' => 'C', 'postal_code' => 'D', 'country' => 'GB'],
        ];
    }

    public function test_retrying_with_the_same_key_returns_the_original_order(): void
    {
        $this->actingAsRole(Role::Sales);
        $headers = ['Idempotency-Key' => 'checkout-7f3a9c21'];

        $first = $this->postJson('/api/v1/orders', $this->payload, $headers)->assertCreated();
        $retry = $this->postJson('/api/v1/orders', $this->payload, $headers)->assertCreated();

        $retry->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $retry->json('data.id'));
        $this->assertSame(1, Order::count());
        $this->assertSame(5, StockLevel::value('reserved'));
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $this->actingAsRole(Role::Sales);
        $headers = ['Idempotency-Key' => 'checkout-7f3a9c21'];

        $this->postJson('/api/v1/orders', $this->payload, $headers)->assertCreated();

        $changed = $this->payload;
        $changed['items'][0]['quantity'] = 6;
        $this->postJson('/api/v1/orders', $changed, $headers)->assertUnprocessable();

        $this->assertSame(1, Order::count());
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $headers = ['Idempotency-Key' => 'shared-key-123'];

        $this->actingAsRole(Role::Sales);
        $this->postJson('/api/v1/orders', $this->payload, $headers)->assertCreated();

        $this->actingAsRole(Role::Sales);
        $this->postJson('/api/v1/orders', $this->payload, $headers)
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, Order::count());
    }

    public function test_failed_requests_are_not_stored_so_they_can_be_retried(): void
    {
        $this->actingAsRole(Role::Sales);
        $headers = ['Idempotency-Key' => 'checkout-retry-01'];
        $tooMany = $this->payload;
        $tooMany['items'][0]['quantity'] = 500;

        $this->postJson('/api/v1/orders', $tooMany, $headers)->assertConflict();
        $this->assertSame(0, IdempotencyKey::count());

        StockLevel::query()->update(['on_hand' => 1000]);
        $this->postJson('/api/v1/orders', $tooMany, $headers)->assertCreated();
    }

    public function test_concurrent_request_with_the_same_key_is_rejected(): void
    {
        $user = $this->actingAsRole(Role::Sales);
        $lock = Cache::lock("idempotency:{$user->id}:in-flight-key-1", 30);
        $lock->get();

        $this->postJson('/api/v1/orders', $this->payload, ['Idempotency-Key' => 'in-flight-key-1'])
            ->assertConflict();

        $lock->release();
        $this->assertSame(0, Order::count());
    }

    public function test_malformed_keys_are_rejected(): void
    {
        $this->actingAsRole(Role::Sales);

        $this->postJson('/api/v1/orders', $this->payload, ['Idempotency-Key' => 'bad key!'])
            ->assertBadRequest();
    }
}
