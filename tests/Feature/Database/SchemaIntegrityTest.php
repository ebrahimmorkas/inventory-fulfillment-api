<?php

namespace Tests\Feature\Database;

use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_reserving_more_than_on_hand(): void
    {
        $level = StockLevel::factory()->quantity(5)->create();

        $this->expectException(QueryException::class);

        $level->forceFill(['reserved' => 6])->save();
    }

    public function test_a_product_can_only_have_one_stock_level_per_warehouse(): void
    {
        $level = StockLevel::factory()->create();

        $this->expectException(QueryException::class);

        StockLevel::factory()->create([
            'warehouse_id' => $level->warehouse_id,
            'product_id' => $level->product_id,
        ]);
    }

    public function test_products_with_stock_cannot_be_deleted(): void
    {
        $level = StockLevel::factory()->create();

        $this->expectException(QueryException::class);

        Product::whereKey($level->product_id)->delete();
    }

    public function test_orders_receive_a_sequential_number_after_insert(): void
    {
        $order = Order::factory()->create();

        $this->assertSame(sprintf('SO-%07d', $order->id), $order->fresh()->number);
    }

    public function test_available_quantity_and_reorder_check(): void
    {
        $level = StockLevel::factory()->quantity(30, 12)->create(['reorder_point' => 20]);

        $this->assertSame(18, $level->available);
        $this->assertTrue($level->isBelowReorderPoint());
        $this->assertSame(1, StockLevel::belowReorderPoint()->count());
    }

    public function test_stock_movements_are_immutable(): void
    {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => StockMovementType::Receipt,
            'on_hand_delta' => 10,
            'reserved_delta' => 0,
            'on_hand_after' => 10,
            'reserved_after' => 0,
        ]);

        $this->expectException(LogicException::class);

        $movement->update(['reason' => 'tampered']);
    }
}
